use axum::{
    extract::{Path, State},
    http::{header, HeaderMap, StatusCode},
    response::{
        sse::{Event, KeepAlive, Sse},
        Html, IntoResponse, Response,
    },
    routing::{delete, get, post},
    Json, Router,
};
use futures_util::stream;
use serde::{Deserialize, Serialize};
use serde_json::json;
use std::convert::Infallible;
use std::sync::Arc;
use std::time::Duration;
use tower_http::cors::CorsLayer;

use crate::models::{AccountConfig, ChatCompletionRequest, FailureReport, SuccessReport, SwitchStrategy};
use crate::switcher::TokenSwitcher;
use crate::ui::INDEX_HTML;

pub struct AppState {
    pub switcher: Arc<TokenSwitcher>,
}

pub fn create_router(state: Arc<AppState>) -> Router {
    Router::new()
        .route("/", get(index_handler))
        .route("/api/status", get(status_handler))
        .route("/api/export", get(export_handler))
        .route("/api/switch", post(force_switch_handler))
        .route("/api/strategy", post(update_strategy_handler))
        .route("/api/report", post(report_failure_handler))
        .route("/api/success", post(report_success_handler))
        .route("/api/accounts", post(add_account_handler))
        .route("/api/accounts/:id", delete(delete_account_handler))
        .route("/api/accounts/:id/toggle", post(toggle_account_handler))
        .route("/v1/chat/completions", post(chat_completions_proxy_handler))
        .route("/mock-v1/chat/completions", post(mock_chat_completions_handler))
        .layer(CorsLayer::permissive())
        .with_state(state)
}

async fn index_handler() -> Html<&'static str> {
    Html(INDEX_HTML)
}

#[derive(Serialize)]
struct StatusResponse {
    strategy: SwitchStrategy,
    accounts: Vec<crate::models::AccountView>,
}

async fn status_handler(State(state): State<Arc<AppState>>) -> Json<StatusResponse> {
    let accounts = state.switcher.get_views().await;
    let strategy = state.switcher.get_strategy().await;
    Json(StatusResponse { strategy, accounts })
}

async fn export_handler(State(state): State<Arc<AppState>>) -> Result<Json<crate::models::ExportResponse>, StatusCode> {
    state
        .switcher
        .export_active()
        .await
        .map(Json)
        .ok_or(StatusCode::NOT_FOUND)
}

async fn force_switch_handler(State(state): State<Arc<AppState>>) -> Json<serde_json::Value> {
    if let Some(route) = state.switcher.force_switch_next().await {
        Json(json!(route))
    } else {
        Json(json!({ "id": "none", "name": "No accounts available" }))
    }
}

#[derive(Deserialize)]
struct StrategyUpdate {
    strategy: SwitchStrategy,
}

async fn update_strategy_handler(
    State(state): State<Arc<AppState>>,
    Json(payload): Json<StrategyUpdate>,
) -> StatusCode {
    state.switcher.set_strategy(payload.strategy).await;
    StatusCode::OK
}

async fn report_failure_handler(
    State(state): State<Arc<AppState>>,
    Json(payload): Json<FailureReport>,
) -> StatusCode {
    state
        .switcher
        .report_failure(&payload.id, payload.status_code, payload.error_message)
        .await;
    StatusCode::OK
}

async fn report_success_handler(
    State(state): State<Arc<AppState>>,
    Json(payload): Json<SuccessReport>,
) -> StatusCode {
    state.switcher.report_success(&payload.id).await;
    StatusCode::OK
}

async fn add_account_handler(
    State(state): State<Arc<AppState>>,
    Json(payload): Json<AccountConfig>,
) -> StatusCode {
    state.switcher.add_account(payload).await;
    StatusCode::CREATED
}

async fn delete_account_handler(
    State(state): State<Arc<AppState>>,
    Path(id): Path<String>,
) -> StatusCode {
    if state.switcher.remove_account(&id).await {
        StatusCode::OK
    } else {
        StatusCode::NOT_FOUND
    }
}

#[derive(Deserialize)]
struct TogglePayload {
    enabled: bool,
}

async fn toggle_account_handler(
    State(state): State<Arc<AppState>>,
    Path(id): Path<String>,
    Json(payload): Json<TogglePayload>,
) -> StatusCode {
    if state.switcher.toggle_account(&id, payload.enabled).await {
        StatusCode::OK
    } else {
        StatusCode::NOT_FOUND
    }
}

/// Reverse proxy handler that forwards to active route and streams back tokens!
async fn chat_completions_proxy_handler(
    State(state): State<Arc<AppState>>,
    Json(mut payload): Json<ChatCompletionRequest>,
) -> Response {
    let route = match state.switcher.get_active_route().await {
        Some(r) => r,
        None => {
            return (
                StatusCode::SERVICE_UNAVAILABLE,
                Json(json!({ "error": "No active healthy route found in token pool" })),
            )
                .into_response();
        }
    };

    // If payload model is empty, use the routed model
    if payload.model.is_none() {
        payload.model = Some(route.model.clone());
    }

    // Check if target is the internal mock endpoint
    if route.base_url.contains("/mock-v1") {
        return mock_chat_stream(&route.id, &route.name, &route.model, &payload);
    }

    // Real upstream request via reqwest
    let client = reqwest::Client::new();
    let upstream_url = format!("{}/chat/completions", route.base_url.trim_end_matches('/'));

    let is_stream = payload.stream.unwrap_or(false);

    let res = client
        .post(&upstream_url)
        .header(header::AUTHORIZATION, format!("Bearer {}", route.api_key))
        .header(header::CONTENT_TYPE, "application/json")
        .json(&payload)
        .send()
        .await;

    match res {
        Ok(upstream_res) => {
            let status = upstream_res.status();
            if status.is_success() {
                state.switcher.report_success(&route.id).await;

                let mut headers = HeaderMap::new();
                headers.insert("X-Routed-Account", route.name.parse().unwrap_or(header::HeaderValue::from_static("account")));
                headers.insert("X-Routed-Model", route.model.parse().unwrap_or(header::HeaderValue::from_static("model")));

                if is_stream {
                    headers.insert(header::CONTENT_TYPE, "text/event-stream".parse().unwrap());
                    headers.insert("Cache-Control", "no-cache".parse().unwrap());
                    headers.insert("X-Accel-Buffering", "no".parse().unwrap());

                    let stream = upstream_res.bytes_stream();
                    (StatusCode::OK, headers, axum::body::Body::from_stream(stream)).into_response()
                } else {
                    let bytes = upstream_res.bytes().await.unwrap_or_default();
                    (StatusCode::OK, headers, bytes).into_response()
                }
            } else {
                let status_u16 = status.as_u16();
                let err_text = upstream_res.text().await.unwrap_or_default();
                state.switcher.report_failure(&route.id, Some(status_u16), Some(err_text.clone())).await;

                // Return error to client so they can observe rotation
                (
                    status,
                    Json(json!({
                        "error": {
                            "message": format!("Upstream {} failed (HTTP {}): {}", route.name, status_u16, err_text),
                            "switched_from": route.id
                        }
                    })),
                )
                    .into_response()
            }
        }
        Err(e) => {
            state.switcher.report_failure(&route.id, None, Some(e.to_string())).await;
            (
                StatusCode::BAD_GATEWAY,
                Json(json!({ "error": format!("Connection to {} failed: {}", route.base_url, e) })),
            )
                .into_response()
        }
    }
}

/// Handler for the local mock LLM provider (simulating realistic token streaming)
async fn mock_chat_completions_handler(
    Json(payload): Json<ChatCompletionRequest>,
) -> Response {
    mock_chat_stream("mock-internal", "Internal Mock Provider", "gpt-4o", &payload)
}

fn mock_chat_stream(
    acc_id: &str,
    acc_name: &str,
    model: &str,
    payload: &ChatCompletionRequest,
) -> Response {
    let last_user_msg = payload
        .messages
        .iter()
        .rev()
        .find(|m| m.role == "user")
        .map(|m| m.content.clone())
        .unwrap_or_else(|| "Hello".to_string());

    let response_text = format!(
        "Hello! I am streaming live from TokenSwitch in Rust.\n\n\
        - Routed Account: **{}** (`{}`)\n\
        - Model in use: `{}`\n\
        - Received prompt: *\"{}\"*\n\n\
        Real-time SSE token stream successfully verified!",
        acc_name, acc_id, model, last_user_msg
    );

    let words: Vec<String> = response_text
        .split(' ')
        .map(|s| format!("{} ", s))
        .collect();

    let stream = stream::unfold((words, 0usize), move |(words, idx)| async move {
        if idx < words.len() {
            tokio::time::sleep(Duration::from_millis(30)).await;
            let chunk = json!({
                "id": "chatcmpl-mock",
                "object": "chat.completion.chunk",
                "created": 1700000000,
                "model": "gpt-4o",
                "choices": [{
                    "index": 0,
                    "delta": { "content": words[idx] },
                    "finish_reason": null
                }]
            });
            let event = Event::default().data(chunk.to_string());
            Some((Ok::<_, Infallible>(event), (words, idx + 1)))
        } else if idx == words.len() {
            // Send final [DONE] marker
            let event = Event::default().data("[DONE]");
            Some((Ok::<_, Infallible>(event), (words, idx + 1)))
        } else {
            None
        }
    });

    let mut headers = HeaderMap::new();
    headers.insert("X-Routed-Account", acc_name.parse().unwrap_or(header::HeaderValue::from_static("mock")));
    headers.insert("X-Routed-Model", model.parse().unwrap_or(header::HeaderValue::from_static("model")));
    headers.insert("X-Accel-Buffering", "no".parse().unwrap());

    (headers, Sse::new(stream).keep_alive(KeepAlive::default())).into_response()
}
