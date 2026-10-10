use serde::{Deserialize, Serialize};
use std::time::Instant;

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct AccountConfig {
    pub id: String,
    pub name: String,
    pub base_url: String,
    pub api_key: String,
    pub model: String,
    #[serde(default = "default_priority")]
    pub priority: u32,
    #[serde(default = "default_cooldown")]
    pub cooldown_secs: u64,
    #[serde(default = "default_true")]
    pub enabled: bool,
}

fn default_priority() -> u32 {
    1
}

fn default_cooldown() -> u64 {
    60
}

fn default_true() -> bool {
    true
}

#[derive(Debug, Clone)]
pub struct AccountRuntimeState {
    pub failure_count: u32,
    pub total_requests: u64,
    pub total_successes: u64,
    pub total_failures: u64,
    pub cooldown_until: Option<Instant>,
    pub last_used: Option<Instant>,
    pub last_error: Option<String>,
}

impl Default for AccountRuntimeState {
    fn default() -> Self {
        Self {
            failure_count: 0,
            total_requests: 0,
            total_successes: 0,
            total_failures: 0,
            cooldown_until: None,
            last_used: None,
            last_error: None,
        }
    }
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct AccountView {
    pub id: String,
    pub name: String,
    pub base_url: String,
    pub api_key: String,
    pub model: String,
    pub priority: u32,
    pub cooldown_secs: u64,
    pub enabled: bool,
    pub is_healthy: bool,
    pub is_cooling_down: bool,
    pub cooldown_remaining_secs: u64,
    pub failure_count: u32,
    pub total_requests: u64,
    pub total_successes: u64,
    pub total_failures: u64,
    pub last_error: Option<String>,
    pub is_active_route: bool,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "snake_case")]
pub enum SwitchStrategy {
    PriorityFallback,
    RoundRobin,
    LeastErrors,
}

impl Default for SwitchStrategy {
    fn default() -> Self {
        Self::PriorityFallback
    }
}

/// The exported route configuration that consumers/clients require
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ExportedRoute {
    pub id: String,
    pub name: String,
    pub base_url: String,
    pub api_key: String,
    pub model: String,
}

#[derive(Debug, Serialize, Deserialize)]
pub struct ExportResponse {
    pub route: ExportedRoute,
    pub env_format: String,
    pub json_format: String,
    pub bash_export: String,
    pub powershell_export: String,
}

#[derive(Debug, Deserialize)]
pub struct FailureReport {
    pub id: String,
    pub status_code: Option<u16>,
    pub error_message: Option<String>,
}

#[derive(Debug, Deserialize)]
pub struct SuccessReport {
    pub id: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ChatMessage {
    pub role: String,
    pub content: String,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct ChatCompletionRequest {
    #[serde(default)]
    pub model: Option<String>,
    pub messages: Vec<ChatMessage>,
    #[serde(default)]
    pub stream: Option<bool>,
    #[serde(default)]
    pub temperature: Option<f32>,
    #[serde(default)]
    pub max_tokens: Option<u32>,
}
