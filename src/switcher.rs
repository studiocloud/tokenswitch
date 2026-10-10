use std::collections::HashMap;
use std::fs;
use std::path::Path;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::Arc;
use std::time::{Duration, Instant};
use tokio::sync::RwLock;
use tracing::{info, warn};

use crate::models::{
    AccountConfig, AccountRuntimeState, AccountView, ExportResponse, ExportedRoute, SwitchStrategy,
};

pub struct TokenSwitcher {
    accounts: RwLock<Vec<AccountConfig>>,
    runtime: RwLock<HashMap<String, AccountRuntimeState>>,
    strategy: RwLock<SwitchStrategy>,
    round_robin_idx: AtomicUsize,
    save_path: Option<String>,
}

impl TokenSwitcher {
    pub fn new(accounts: Vec<AccountConfig>, strategy: SwitchStrategy, save_path: Option<String>) -> Arc<Self> {
        let mut runtime = HashMap::new();
        for acc in &accounts {
            runtime.insert(acc.id.clone(), AccountRuntimeState::default());
        }

        Arc::new(Self {
            accounts: RwLock::new(accounts),
            runtime: RwLock::new(runtime),
            strategy: RwLock::new(strategy),
            round_robin_idx: AtomicUsize::new(0),
            save_path,
        })
    }

    /// Load or create default accounts from a JSON config file
    pub fn load_or_init(config_path: &str) -> Arc<Self> {
        if Path::new(config_path).exists() {
            if let Ok(data) = fs::read_to_string(config_path) {
                if let Ok(accounts) = serde_json::from_str::<Vec<AccountConfig>>(&data) {
                    info!("Loaded {} accounts from {}", accounts.len(), config_path);
                    return Self::new(accounts, SwitchStrategy::PriorityFallback, Some(config_path.to_string()));
                }
            }
        }

        // Initialize with default demo/local mock accounts for quick testing
        let default_accounts = vec![
            AccountConfig {
                id: "codex-account-1".to_string(),
                name: "Codex Local Primary".to_string(),
                base_url: "http://127.0.0.1:8080/mock-v1".to_string(),
                api_key: "sk-codex-test-key-alpha".to_string(),
                model: "gpt-4o".to_string(),
                priority: 1,
                cooldown_secs: 30,
                enabled: true,
            },
            AccountConfig {
                id: "codex-account-2".to_string(),
                name: "Codex Secondary Failover".to_string(),
                base_url: "http://127.0.0.1:8080/mock-v1".to_string(),
                api_key: "sk-codex-test-key-beta".to_string(),
                model: "gpt-4o-mini".to_string(),
                priority: 2,
                cooldown_secs: 45,
                enabled: true,
            },
            AccountConfig {
                id: "codex-account-3".to_string(),
                name: "Codex Tertiary Backup".to_string(),
                base_url: "http://127.0.0.1:8080/mock-v1".to_string(),
                api_key: "sk-codex-test-key-gamma".to_string(),
                model: "gpt-3.5-turbo".to_string(),
                priority: 3,
                cooldown_secs: 60,
                enabled: true,
            },
        ];

        let switcher = Self::new(default_accounts, SwitchStrategy::PriorityFallback, Some(config_path.to_string()));
        let _ = switcher.save_to_file();
        switcher
    }

    pub fn save_to_file(&self) -> Result<(), String> {
        if let Some(path) = &self.save_path {
            // Non-blocking try or synchronous fs
            if let Ok(accounts) = self.accounts.try_read() {
                if let Ok(json) = serde_json::to_string_pretty(&*accounts) {
                    let _ = fs::write(path, json);
                }
            }
        }
        Ok(())
    }

    pub async fn add_account(&self, acc: AccountConfig) {
        let mut accounts = self.accounts.write().await;
        let mut runtime = self.runtime.write().await;
        runtime.insert(acc.id.clone(), AccountRuntimeState::default());
        accounts.retain(|a| a.id != acc.id);
        accounts.push(acc);
        drop(accounts);
        drop(runtime);
        let _ = self.save_to_file();
    }

    pub async fn update_account(&self, acc: AccountConfig) -> bool {
        let mut accounts = self.accounts.write().await;
        if let Some(existing) = accounts.iter_mut().find(|a| a.id == acc.id) {
            *existing = acc;
            drop(accounts);
            let _ = self.save_to_file();
            true
        } else {
            false
        }
    }

    pub async fn remove_account(&self, id: &str) -> bool {
        let mut accounts = self.accounts.write().await;
        let prev_len = accounts.len();
        accounts.retain(|a| a.id != id);
        let mut runtime = self.runtime.write().await;
        runtime.remove(id);
        let removed = accounts.len() < prev_len;
        drop(accounts);
        drop(runtime);
        if removed {
            let _ = self.save_to_file();
        }
        removed
    }

    pub async fn toggle_account(&self, id: &str, enabled: bool) -> bool {
        let mut accounts = self.accounts.write().await;
        if let Some(acc) = accounts.iter_mut().find(|a| a.id == id) {
            acc.enabled = enabled;
            drop(accounts);
            let _ = self.save_to_file();
            true
        } else {
            false
        }
    }

    pub async fn set_strategy(&self, strategy: SwitchStrategy) {
        let mut strat = self.strategy.write().await;
        *strat = strategy;
    }

    pub async fn get_strategy(&self) -> SwitchStrategy {
        self.strategy.read().await.clone()
    }

    /// Selects the currently active healthy route based on the chosen strategy
    pub async fn get_active_route(&self) -> Option<ExportedRoute> {
        let accounts = self.accounts.read().await;
        let runtime = self.runtime.read().await;
        let strategy = self.strategy.read().await;
        let now = Instant::now();

        // Filter healthy and enabled accounts
        let mut eligible: Vec<&AccountConfig> = accounts
            .iter()
            .filter(|acc| {
                if !acc.enabled {
                    return false;
                }
                if let Some(state) = runtime.get(&acc.id) {
                    if let Some(until) = state.cooldown_until {
                        if until > now {
                            return false; // Still cooling down
                        }
                    }
                }
                true
            })
            .collect();

        if eligible.is_empty() {
            // If all eligible accounts are cooling down, fall back to the one whose cooldown expires soonest
            let fallback = accounts
                .iter()
                .filter(|acc| acc.enabled)
                .min_by_key(|acc| {
                    if let Some(state) = runtime.get(&acc.id) {
                        state.cooldown_until.map(|u| u.saturating_duration_since(now)).unwrap_or(Duration::ZERO)
                    } else {
                        Duration::ZERO
                    }
                });

            return fallback.map(|acc| ExportedRoute {
                id: acc.id.clone(),
                name: acc.name.clone(),
                base_url: acc.base_url.clone(),
                api_key: acc.api_key.clone(),
                model: acc.model.clone(),
            });
        }

        let selected = match *strategy {
            SwitchStrategy::PriorityFallback => {
                // Sort by priority ascending (1 = highest priority)
                eligible.sort_by_key(|a| a.priority);
                eligible.first().copied()
            }
            SwitchStrategy::RoundRobin => {
                let idx = self.round_robin_idx.load(Ordering::Relaxed) % eligible.len();
                eligible.get(idx).copied()
            }
            SwitchStrategy::LeastErrors => {
                eligible.sort_by_key(|a| {
                    runtime.get(&a.id).map(|s| s.failure_count).unwrap_or(0)
                });
                eligible.first().copied()
            }
        };

        selected.map(|acc| ExportedRoute {
            id: acc.id.clone(),
            name: acc.name.clone(),
            base_url: acc.base_url.clone(),
            api_key: acc.api_key.clone(),
            model: acc.model.clone(),
        })
    }

    /// Manually triggers a switch to the next account
    pub async fn force_switch_next(&self) -> Option<ExportedRoute> {
        let accounts = self.accounts.read().await;
        if accounts.is_empty() {
            return None;
        }
        self.round_robin_idx.fetch_add(1, Ordering::Relaxed);
        drop(accounts);
        self.get_active_route().await
    }

    /// Reports a failure for an account. If 429/quota or server error, sets cooldown and switches!
    pub async fn report_failure(&self, id: &str, status_code: Option<u16>, err: Option<String>) {
        let accounts = self.accounts.read().await;
        let acc_opt = accounts.iter().find(|a| a.id == id).cloned();
        drop(accounts);

        let Some(acc) = acc_opt else { return };

        let mut runtime = self.runtime.write().await;
        let state = runtime.entry(id.to_string()).or_default();
        state.failure_count += 1;
        state.total_failures += 1;
        state.total_requests += 1;
        state.last_error = err.clone();
        state.last_used = Some(Instant::now());

        let is_rate_limit_or_fatal = match status_code {
            Some(429) => true, // Too Many Requests / Quota Exceeded
            Some(401) | Some(403) => true, // Invalid token or permissions
            Some(500) | Some(502) | Some(503) | Some(504) => true, // Upstream error
            None => true, // Connection failure / timeout
            _ => false,
        };

        if is_rate_limit_or_fatal {
            // Apply cooldown
            let cooldown_dur = Duration::from_secs(acc.cooldown_secs);
            state.cooldown_until = Some(Instant::now() + cooldown_dur);
            warn!(
                "Account [{}] hit failure (status={:?}, error={:?}). Cooldown activated for {}s.",
                acc.name, status_code, err, acc.cooldown_secs
            );
            // Advance round-robin cursor to trigger switch immediately
            self.round_robin_idx.fetch_add(1, Ordering::Relaxed);
        }
    }

    /// Reports a successful request for an account
    pub async fn report_success(&self, id: &str) {
        let mut runtime = self.runtime.write().await;
        if let Some(state) = runtime.get_mut(id) {
            state.failure_count = 0;
            state.total_successes += 1;
            state.total_requests += 1;
            state.cooldown_until = None;
            state.last_used = Some(Instant::now());
        }
    }

    /// Returns the UI view models for all accounts
    pub async fn get_views(&self) -> Vec<AccountView> {
        let accounts = self.accounts.read().await;
        let runtime = self.runtime.read().await;
        let active_route = self.get_active_route().await;
        let active_id = active_route.as_ref().map(|r| r.id.as_str());
        let now = Instant::now();

        accounts
            .iter()
            .map(|acc| {
                let state = runtime.get(&acc.id);
                let (cooldown_remaining_secs, is_cooling_down) = match state.and_then(|s| s.cooldown_until) {
                    Some(until) if until > now => (until.duration_since(now).as_secs(), true),
                    _ => (0, false),
                };

                let failure_count = state.map(|s| s.failure_count).unwrap_or(0);
                let total_requests = state.map(|s| s.total_requests).unwrap_or(0);
                let total_successes = state.map(|s| s.total_successes).unwrap_or(0);
                let total_failures = state.map(|s| s.total_failures).unwrap_or(0);
                let last_error = state.and_then(|s| s.last_error.clone());
                let is_healthy = acc.enabled && !is_cooling_down;
                let is_active_route = active_id == Some(&acc.id);

                AccountView {
                    id: acc.id.clone(),
                    name: acc.name.clone(),
                    base_url: acc.base_url.clone(),
                    api_key: acc.api_key.clone(),
                    model: acc.model.clone(),
                    priority: acc.priority,
                    cooldown_secs: acc.cooldown_secs,
                    enabled: acc.enabled,
                    is_healthy,
                    is_cooling_down,
                    cooldown_remaining_secs,
                    failure_count,
                    total_requests,
                    total_successes,
                    total_failures,
                    last_error,
                    is_active_route,
                }
            })
            .collect()
    }

    /// Exports active configuration in multiple formats for cPanel or client apps
    pub async fn export_active(&self) -> Option<ExportResponse> {
        let route = self.get_active_route().await?;

        let env_format = format!(
            "OPENAI_BASE_URL=\"{}\"\nOPENAI_API_KEY=\"{}\"\nOPENAI_MODEL=\"{}\"",
            route.base_url, route.api_key, route.model
        );

        let json_format = serde_json::to_string_pretty(&route).unwrap_or_default();

        let bash_export = format!(
            "export OPENAI_BASE_URL=\"{}\"\nexport OPENAI_API_KEY=\"{}\"\nexport OPENAI_MODEL=\"{}\"",
            route.base_url, route.api_key, route.model
        );

        let powershell_export = format!(
            "$env:OPENAI_BASE_URL=\"{}\"\n$env:OPENAI_API_KEY=\"{}\"\n$env:OPENAI_MODEL=\"{}\"",
            route.base_url, route.api_key, route.model
        );

        Some(ExportResponse {
            route,
            env_format,
            json_format,
            bash_export,
            powershell_export,
        })
    }
}
