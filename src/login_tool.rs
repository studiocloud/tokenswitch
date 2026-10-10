use std::collections::HashMap;
use std::io::{Read, Write};
use std::net::{TcpListener, TcpStream};
use std::process::Command;
use std::sync::{Arc, Mutex};
use std::time::{SystemTime, UNIX_EPOCH};

const OPENAI_CLIENT_ID: &str = "app_EMoamEEZ73f0CkXaXp7hrann";
const TOKENSWITCH_SERVER: &str = "https://tokenswitch.zipperi.com";
const TOKENSWITCH_AUTH: &str = "Bearer Its.me@12345";
const REDIRECT_PORT: u16 = 1455;

// Pure Rust SHA-256 implementation (zero external dependencies)
fn sha256(data: &[u8]) -> [u8; 32] {
    let k: [u32; 64] = [
        0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
        0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
        0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
        0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
        0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
        0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
        0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
        0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
    ];

    let mut h: [u32; 8] = [
        0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a,
        0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19,
    ];

    let mut msg = data.to_vec();
    let bit_len = (data.len() as u64) * 8;
    msg.push(0x80);
    while (msg.len() % 64) != 56 {
        msg.push(0);
    }
    msg.extend_from_slice(&bit_len.to_be_bytes());

    for chunk in msg.chunks_exact(64) {
        let mut w = [0u32; 64];
        for i in 0..16 {
            w[i] = u32::from_be_bytes([chunk[i * 4], chunk[i * 4 + 1], chunk[i * 4 + 2], chunk[i * 4 + 3]]);
        }
        for i in 16..64 {
            let s0 = w[i - 15].rotate_right(7) ^ w[i - 15].rotate_right(18) ^ (w[i - 15] >> 3);
            let s1 = w[i - 2].rotate_right(17) ^ w[i - 2].rotate_right(19) ^ (w[i - 2] >> 10);
            w[i] = w[i - 16].wrapping_add(s0).wrapping_add(w[i - 7]).wrapping_add(s1);
        }

        let mut a = h[0];
        let mut b = h[1];
        let mut c = h[2];
        let mut d = h[3];
        let mut e = h[4];
        let mut f = h[5];
        let mut g = h[6];
        let mut h_val = h[7];

        for i in 0..64 {
            let s1 = e.rotate_right(6) ^ e.rotate_right(11) ^ e.rotate_right(25);
            let ch = (e & f) ^ ((!e) & g);
            let temp1 = h_val.wrapping_add(s1).wrapping_add(ch).wrapping_add(k[i]).wrapping_add(w[i]);
            let s0 = a.rotate_right(2) ^ a.rotate_right(13) ^ a.rotate_right(22);
            let maj = (a & b) ^ (a & c) ^ (b & c);
            let temp2 = s0.wrapping_add(maj);

            h_val = g;
            g = f;
            f = e;
            e = d.wrapping_add(temp1);
            d = c;
            c = b;
            b = a;
            a = temp1.wrapping_add(temp2);
        }

        h[0] = h[0].wrapping_add(a);
        h[1] = h[1].wrapping_add(b);
        h[2] = h[2].wrapping_add(c);
        h[3] = h[3].wrapping_add(d);
        h[4] = h[4].wrapping_add(e);
        h[5] = h[5].wrapping_add(f);
        h[6] = h[6].wrapping_add(g);
        h[7] = h[7].wrapping_add(h_val);
    }

    let mut out = [0u8; 32];
    for (i, val) in h.iter().enumerate() {
        out[i * 4..(i + 1) * 4].copy_from_slice(&val.to_be_bytes());
    }
    out
}

fn base64url(input: &[u8]) -> String {
    const CHARSET: &[u8] = b"ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_";
    let mut out = String::new();
    let mut i = 0;
    while i < input.len() {
        let b0 = input[i];
        let b1 = if i + 1 < input.len() { input[i + 1] } else { 0 };
        let b2 = if i + 2 < input.len() { input[i + 2] } else { 0 };

        let triple = ((b0 as u32) << 16) | ((b1 as u32) << 8) | (b2 as u32);
        out.push(CHARSET[((triple >> 18) & 0x3F) as usize] as char);
        out.push(CHARSET[((triple >> 12) & 0x3F) as usize] as char);
        if i + 1 < input.len() {
            out.push(CHARSET[((triple >> 6) & 0x3F) as usize] as char);
        }
        if i + 2 < input.len() {
            out.push(CHARSET[(triple & 0x3F) as usize] as char);
        }
        i += 3;
    }
    out
}

fn random_string(len: usize) -> String {
    let now = SystemTime::now().duration_since(UNIX_EPOCH).unwrap().as_nanos();
    let mut seed = (now ^ 0x9e3779b97f4a7c15) as u64;
    const CHARSET: &[u8] = b"abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
    let mut out = String::with_capacity(len);
    for _ in 0..len {
        seed = seed.wrapping_mul(6364136223846793005).wrapping_add(1442695040888963407);
        let idx = (seed >> 32) as usize % CHARSET.len();
        out.push(CHARSET[idx] as char);
    }
    out
}

fn extract_json_field(json: &str, field: &str) -> Option<String> {
    let needle = format!("\"{}\":", field);
    if let Some(pos) = json.find(&needle) {
        let rest = &json[pos + needle.len()..].trim_start();
        if rest.starts_with('"') {
            let inner = &rest[1..];
            let mut escaped = false;
            let mut val = String::new();
            for c in inner.chars() {
                if escaped {
                    val.push(c);
                    escaped = false;
                } else if c == '\\' {
                    escaped = true;
                } else if c == '"' {
                    return Some(val);
                } else {
                    val.push(c);
                }
            }
        } else {
            let end = rest.find(|c| c == ',' || c == '}' || c == ']').unwrap_or(rest.len());
            return Some(rest[..end].trim().to_string());
        }
    }
    None
}

fn extract_email_from_jwt(jwt: &str) -> Option<String> {
    let parts: Vec<&str> = jwt.split('.').collect();
    if parts.len() < 2 {
        return None;
    }
    let payload_b64 = parts[1];
    let mut padded = payload_b64.replace('-', "+").replace('_', "/");
    while padded.len() % 4 != 0 {
        padded.push('=');
    }
    let decoded = base64_decode(&padded)?;
    let json = String::from_utf8_lossy(&decoded);
    extract_json_field(&json, "email")
}

fn base64_decode(input: &str) -> Option<Vec<u8>> {
    let mut out = Vec::new();
    let mut buf = 0u32;
    let mut bits = 0;
    for &b in input.as_bytes() {
        if b == b'=' {
            break;
        }
        let val = match b {
            b'A'..=b'Z' => b - b'A',
            b'a'..=b'z' => b - b'a' + 26,
            b'0'..=b'9' => b - b'0' + 52,
            b'+' => 62,
            b'/' => 63,
            _ => continue,
        } as u32;
        buf = (buf << 6) | val;
        bits += 6;
        if bits >= 8 {
            bits -= 8;
            out.push(((buf >> bits) & 0xFF) as u8);
        }
    }
    Some(out)
}

fn open_browser(url: &str) {
    let chrome_path = r"C:\Program Files\Google\Chrome\Application\chrome.exe";
    let edge_path = r"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe";

    if std::path::Path::new(chrome_path).exists() {
        let _ = Command::new(chrome_path).arg(url).spawn();
        return;
    } else if std::path::Path::new(edge_path).exists() {
        let _ = Command::new(edge_path).arg(url).spawn();
        return;
    }

    let _ = Command::new("cmd").args(&["/c", "start", "", url]).spawn();
}

fn exchange_code_for_tokens(code: &str, code_verifier: &str) -> Option<(String, String, Option<String>)> {
    println!("\n  [..] Exchanging authorization code with auth.openai.com...");

    let redirect_uri = format!("http://localhost:{}/auth/callback", REDIRECT_PORT);
    let post_data = format!(
        "grant_type=authorization_code&client_id={}&code={}&redirect_uri={}&code_verifier={}",
        OPENAI_CLIENT_ID,
        code,
        redirect_uri.replace(":", "%3A").replace("/", "%2F"),
        code_verifier
    );

    let output = Command::new(r"C:\Windows\System32\curl.exe")
        .args(&[
            "-s",
            "-X", "POST",
            "https://auth.openai.com/oauth/token",
            "-H", "Content-Type: application/x-www-form-urlencoded",
            "-d", &post_data,
        ])
        .output()
        .ok()?;

    let res_text = String::from_utf8_lossy(&output.stdout);

    let access_token = extract_json_field(&res_text, "access_token")?;
    let refresh_token = extract_json_field(&res_text, "refresh_token")
        .unwrap_or_else(|| "".to_string());
    let id_token = extract_json_field(&res_text, "id_token");

    let email = if let Some(ref idt) = id_token {
        extract_email_from_jwt(idt)
    } else {
        extract_email_from_jwt(&access_token)
    };

    Some((access_token, refresh_token, email))
}

fn sync_to_tokenswitch(
    access_token: &str,
    refresh_token: &str,
    email: Option<&str>,
    model: &str,
) -> bool {
    println!("  [..] Registering account into TokenSwitch cluster (https://tokenswitch.zipperi.com)...");

    let display_name = match email {
        Some(em) => format!("OpenAI Account ({})", em),
        None => "OpenAI Account (Browser Linked)".to_string(),
    };

    let payload = format!(
        "{{\"token\":\"{}\",\"refresh_token\":\"{}\",\"model\":\"{}\",\"name\":\"{}\",\"priority\":1,\"cooldown_secs\":60}}",
        access_token,
        refresh_token,
        model,
        display_name
    );

    let output = Command::new(r"C:\Windows\System32\curl.exe")
        .args(&[
            "-s",
            "-X", "POST",
            &format!("{}/api/accounts/oauth-connect", TOKENSWITCH_SERVER),
            "-H", &format!("Authorization: {}", TOKENSWITCH_AUTH),
            "-H", "Content-Type: application/json",
            "-d", &payload,
        ])
        .output();

    match output {
        Ok(out) => {
            let res = String::from_utf8_lossy(&out.stdout);
            if res.contains("\"status\":\"ok\"") || res.contains("Successfully") {
                println!("  [OK] Success! Server response: {}", res.trim());
                true
            } else {
                println!("  [!] Server responded: {}", res.trim());
                res.contains("\"status\":\"ok\"")
            }
        }
        Err(e) => {
            println!("  [!] Failed to connect to TokenSwitch server: {}", e);
            false
        }
    }
}

fn save_local_backup(email: Option<&str>, access_token: &str, refresh_token: &str, model: &str) {
    if let Ok(home) = std::env::var("USERPROFILE") {
        let dir = format!("{}\\.tokenswitch", home);
        let _ = std::fs::create_dir_all(&dir);
        let file_path = format!("{}\\accounts.json", dir);

        let email_str = email.unwrap_or("unknown");
        let entry = format!(
            "    {{\n      \"email\": \"{}\",\n      \"model\": \"{}\",\n      \"access_token\": \"{}\",\n      \"refresh_token\": \"{}\",\n      \"connected_at\": {}\n    }}",
            email_str,
            model,
            access_token,
            refresh_token,
            SystemTime::now().duration_since(UNIX_EPOCH).unwrap().as_secs()
        );

        let mut existing = std::fs::read_to_string(&file_path).unwrap_or_else(|_| "[\n]".to_string());
        if existing.trim() == "[\n]" || existing.trim() == "[]" {
            existing = format!("[\n{}\n]", entry);
        } else if let Some(last_bracket) = existing.rfind(']') {
            existing.insert_str(last_bracket, &format!(",\n{}\n", entry));
        }
        let _ = std::fs::write(&file_path, existing);
        println!("  [OK] Local token backup saved to {}", file_path);
    }
}

// Global verifier storage for multi-turn sessions
type VerifierStore = Arc<Mutex<HashMap<String, String>>>;

fn handle_client(mut stream: TcpStream, store: VerifierStore) {
    let _ = stream.set_read_timeout(Some(std::time::Duration::from_secs(5)));
    let _ = stream.set_write_timeout(Some(std::time::Duration::from_secs(5)));

    let mut buf = [0u8; 4096];
    let n = match stream.read(&mut buf) {
        Ok(n) if n > 0 => n,
        _ => return,
    };
    let req = String::from_utf8_lossy(&buf[..n]);
    let first_line = req.lines().next().unwrap_or("");
    let parts: Vec<&str> = first_line.split_whitespace().collect();
    if parts.len() < 2 {
        return;
    }

    let method = parts[0];
    let uri = parts[1];

    if method == "OPTIONS" {
        let resp = "HTTP/1.1 204 No Content\r\nAccess-Control-Allow-Origin: *\r\nAccess-Control-Allow-Methods: GET, POST, OPTIONS\r\nAccess-Control-Allow-Headers: *\r\nAccess-Control-Allow-Private-Network: true\r\nConnection: close\r\n\r\n";
        let _ = stream.write_all(resp.as_bytes());
        return;
    }

    if uri == "/ping" {
        let resp = "HTTP/1.1 200 OK\r\nAccess-Control-Allow-Origin: *\r\nAccess-Control-Allow-Private-Network: true\r\nContent-Type: application/json\r\nContent-Length: 15\r\nConnection: close\r\n\r\n{\"status\":\"ok\"}";
        let _ = stream.write_all(resp.as_bytes());
        return;
    }

    if uri == "/" || uri == "/login" || uri.starts_with("/start") {
        let code_verifier = random_string(64);
        let code_challenge = base64url(&sha256(code_verifier.as_bytes()));
        let state = random_string(16);

        {
            let mut map = store.lock().unwrap();
            map.insert(state.clone(), code_verifier);
        }

        let auth_url = format!(
            "https://auth.openai.com/oauth/authorize?response_type=code&client_id={}&redirect_uri=http%3A%2F%2Flocalhost%3A{}%2Fauth%2Fcallback&scope=openid+profile+email+offline_access&code_challenge={}&code_challenge_method=S256&id_token_add_organizations=true&codex_cli_simplified_flow=true&state={}&originator=codex_cli_rs&prompt=login",
            OPENAI_CLIENT_ID,
            REDIRECT_PORT,
            code_challenge,
            state
        );

        let resp = format!(
            "HTTP/1.1 302 Found\r\nLocation: {}\r\nCache-Control: no-store\r\nConnection: close\r\n\r\n",
            auth_url
        );
        let _ = stream.write_all(resp.as_bytes());
        return;
    }

    if uri.starts_with("/auth/callback") {
        let query = match uri.find('?') {
            Some(idx) => &uri[idx + 1..],
            None => "",
        };

        let mut code = String::new();
        let mut state = String::new();

        for pair in query.split('&') {
            let mut kv = pair.splitn(2, '=');
            let k = kv.next().unwrap_or("");
            let v = kv.next().unwrap_or("");
            if k == "code" {
                code = v.to_string();
            } else if k == "state" {
                state = v.to_string();
            }
        }

        let verifier = {
            let map = store.lock().unwrap();
            map.get(&state).cloned()
        };

        if code.is_empty() {
            let resp = "HTTP/1.1 400 Bad Request\r\nContent-Type: text/plain\r\nConnection: close\r\n\r\nMissing authorization code.";
            let _ = stream.write_all(resp.as_bytes());
            return;
        }

        let verifier = verifier.unwrap_or_else(|| {
            random_string(64)
        });

        println!("\n  [OK] Captured authorization code from OpenAI callback!");

        if let Some((access_token, refresh_token, email)) = exchange_code_for_tokens(&code, &verifier) {
            let email_str = email.as_deref().unwrap_or("unknown");
            println!("  [OK] Successfully authenticated OpenAI account: {}", email_str);

            sync_to_tokenswitch(&access_token, &refresh_token, email.as_deref(), "gpt-6-luna");
            save_local_backup(email.as_deref(), &access_token, &refresh_token, "gpt-6-luna");

            let target = format!("{}/?connected=1&email={}", TOKENSWITCH_SERVER, email_str);
            let html_body = format!(
                r#"<!DOCTYPE html><html><head><title>Authentication Complete</title><style>body{{background:#0b0d10;color:#fff;font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;}}.card{{background:#13171d;border:1px solid #21262d;border-radius:12px;padding:32px;text-align:center;max-width:380px;}}h2{{color:#3fb950;margin:0 0 10px;font-size:20px;}}p{{color:#8b949e;font-size:14px;line-height:1.5;}}</style></head><body><div class="card"><h2>&#10003; Account Connected!</h2><p><b>{}</b> is now synced into TokenSwitch.<br><br>Closing this popup window...</p></div><script>try{{if(window.opener){{window.opener.postMessage({{type:'tokenswitch_oauth_success',email:'{}'}},'*');}}}}catch(e){{}}setTimeout(function(){{window.close();setTimeout(function(){{window.location.href='{}';}},800);}},800);</script></body></html>"#,
                email_str,
                email_str,
                target
            );

            let resp = format!(
                "HTTP/1.1 200 OK\r\nContent-Type: text/html; charset=utf-8\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{}",
                html_body.len(),
                html_body
            );
            let _ = stream.write_all(resp.as_bytes());
        } else {
            let err_body = "Failed to exchange authorization code for OpenAI tokens.";
            let resp = format!(
                "HTTP/1.1 500 Internal Server Error\r\nContent-Type: text/plain\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{}",
                err_body.len(),
                err_body
            );
            let _ = stream.write_all(resp.as_bytes());
        }
        return;
    }

    let not_found = "HTTP/1.1 404 Not Found\r\nContent-Length: 9\r\nConnection: close\r\n\r\nNot Found";
    let _ = stream.write_all(not_found.as_bytes());
}

fn main() {
    println!("\n========================================================");
    println!("   TOKEN SWITCH - MULTI-ACCOUNT BROWSER LOGIN HELPER    ");
    println!("   Direct OpenAI / ChatGPT Authentication & Auto-Sync   ");
    println!("========================================================\n");

    println!("Target Cluster: {}", TOKENSWITCH_SERVER);
    println!("Loopback Listener: http://localhost:{}", REDIRECT_PORT);

    let listener = match TcpListener::bind(format!("127.0.0.1:{}", REDIRECT_PORT)) {
        Ok(l) => l,
        Err(e) => {
            eprintln!("  [ERROR] Could not bind to port {}: {}", REDIRECT_PORT, e);
            eprintln!("  Port {} is likely already in use by another instance.", REDIRECT_PORT);
            return;
        }
    };

    let store: VerifierStore = Arc::new(Mutex::new(HashMap::new()));

    let args: Vec<String> = std::env::args().collect();
    let should_open = !args.iter().any(|a| a == "--no-open" || a == "--daemon");

    if should_open {
        println!("  [..] Launching browser to authenticate...");
        open_browser(&format!("http://localhost:{}/login", REDIRECT_PORT));
    }

    println!("  [READY] Listening for authentication requests...");
    println!("  You can trigger login anytime by visiting: http://localhost:{}/login\n", REDIRECT_PORT);

    for stream in listener.incoming() {
        if let Ok(s) = stream {
            let store_clone = Arc::clone(&store);
            std::thread::spawn(move || {
                handle_client(s, store_clone);
            });
        }
    }
}
