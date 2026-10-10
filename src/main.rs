use clap::{Parser, Subcommand};
use std::net::SocketAddr;
use std::sync::Arc;
use tracing::info;
use tracing_subscriber::{layer::SubscriberExt, util::SubscriberInitExt};

use tokenswitch::{
    create_router,
    switcher::TokenSwitcher,
    AppState,
};

#[derive(Parser, Debug)]
#[command(name = "tokenswitch")]
#[command(about = "Smart LLM Token Switcher, Failover Router, and Streaming Proxy", long_about = None)]
struct Cli {
    #[command(subcommand)]
    command: Option<Commands>,

    /// Port to listen on (for default serve mode)
    #[arg(short, long, default_value_t = 8080)]
    port: u16,

    /// Path to JSON configuration file
    #[arg(short, long, default_value = "tokens.json")]
    config: String,
}

#[derive(Subcommand, Debug)]
enum Commands {
    /// Start the TokenSwitch HTTP server with Web UI and Streaming Proxy
    Serve {
        #[arg(short, long, default_value_t = 8080)]
        port: u16,
        #[arg(short, long, default_value = "tokens.json")]
        config: String,
    },
    /// Export the currently active route configuration
    Get {
        #[arg(short, long, default_value = "tokens.json")]
        config: String,
        /// Export format: env, json, bash, powershell
        #[arg(short, long, default_value = "env")]
        format: String,
    },
    /// Force switch active route to the next available account
    Switch {
        #[arg(short, long, default_value = "tokens.json")]
        config: String,
    },
    /// Run self-test simulating 429 rate limit failover and rotation
    Test {
        #[arg(short, long, default_value = "tokens.json")]
        config: String,
    },
}

#[tokio::main]
async fn main() -> anyhow::Result<()> {
    tracing_subscriber::registry()
        .with(tracing_subscriber::EnvFilter::try_from_default_env().unwrap_or_else(|_| "info".into()))
        .with(tracing_subscriber::fmt::layer())
        .init();

    let cli = Cli::parse();

    match cli.command.unwrap_or(Commands::Serve { port: cli.port, config: cli.config }) {
        Commands::Serve { port, config } => {
            let switcher = TokenSwitcher::load_or_init(&config);
            let state = Arc::new(AppState { switcher: switcher.clone() });
            let app = create_router(state);

            let addr = SocketAddr::from(([127, 0, 0, 1], port));
            info!("===========================================================");
            info!("TokenSwitch is running on http://{}", addr);
            info!("Dashboard UI:           http://{}", addr);
            info!("Active Route Export:    http://{}/api/export", addr);
            info!("OpenAI Streaming Proxy: http://{}/v1/chat/completions", addr);
            info!("===========================================================");

            let listener = tokio::net::TcpListener::bind(addr).await?;
            axum::serve(listener, app).await?;
        }
        Commands::Get { config, format } => {
            let switcher = TokenSwitcher::load_or_init(&config);
            if let Some(export) = switcher.export_active().await {
                match format.to_lowercase().as_str() {
                    "json" => println!("{}", export.json_format),
                    "bash" => println!("{}", export.bash_export),
                    "powershell" | "ps1" => println!("{}", export.powershell_export),
                    _ => println!("{}", export.env_format),
                }
            } else {
                eprintln!("Error: No active healthy route available in token pool.");
            }
        }
        Commands::Switch { config } => {
            let switcher = TokenSwitcher::load_or_init(&config);
            if let Some(route) = switcher.force_switch_next().await {
                println!("Switched to: {} ({})", route.name, route.id);
                println!("Base URL:    {}", route.base_url);
                println!("API Key:     {}", route.api_key);
                println!("Model:       {}", route.model);
            } else {
                eprintln!("No accounts available to switch to.");
            }
        }
        Commands::Test { config } => {
            let switcher = TokenSwitcher::load_or_init(&config);
            println!("\n=== Running TokenSwitch Failover Verification Test ===");
            
            let initial = switcher.get_active_route().await.expect("Initial route");
            println!("[1] Initial active route: {} (Priority P{})", initial.name, initial.id);

            println!("[2] Simulating 429 Too Many Requests on {}...", initial.id);
            switcher.report_failure(&initial.id, Some(429), Some("Simulated Rate Limit 429".to_string())).await;

            let after_failover = switcher.get_active_route().await.expect("Failover route");
            println!("[3] Automatic Failover succeeded -> New active: {} ({})", after_failover.name, after_failover.id);
            assert_ne!(initial.id, after_failover.id, "Account successfully rotated away from rate-limited key!");

            let views = switcher.get_views().await;
            for v in views {
                println!(
                    "    - Account {}: Healthy={}, CooldownRemaining={}s, Failures={}",
                    v.name, v.is_healthy, v.cooldown_remaining_secs, v.failure_count
                );
            }

            println!("\nTest passed! Automatic switching mechanism operational.\n");
        }
    }

    Ok(())
}
