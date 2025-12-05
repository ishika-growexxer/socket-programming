// public/js/main.js

const log = document.getElementById('log');
const form = document.getElementById('form');
const input = document.getElementById('input');
const urlInput = document.getElementById('url');
const connectBtn = document.getElementById('connect');
const disconnectBtn = document.getElementById('disconnect');
const nameInput = document.getElementById('name');
const setNameBtn = document.getElementById('setName');

let ws = null;

// Add message to chat log
function addLine(text, css = 'other') {
  const wrapper = document.createElement('div');
  wrapper.className = 'msg ' + css;

  // If message is from "other", extract `[name] message`
  if (css === 'other' && text.startsWith('[')) {
    const end = text.indexOf(']');
    const username = text.substring(1, end);
    const message = text.substring(end + 2);

    // Username label
    const label = document.createElement('div');
    label.className = 'username';
    label.textContent = username;
    wrapper.appendChild(label);

    // Message text
    const msgText = document.createElement('div');
    msgText.textContent = message;
    wrapper.appendChild(msgText);

  } else {
    wrapper.textContent = text;
  }
  log.appendChild(wrapper);
  log.scrollTop = log.scrollHeight;
}

// Reset UI to initial state after disconnect
function resetUI() {
  log.innerHTML = '';
  input.value = '';
  nameInput.value = '';
  connectBtn.disabled = false;
  disconnectBtn.disabled = true;

  if (ws && ws.readyState !== WebSocket.CLOSED) {
    ws.close();
  }
  ws = null;

  addLine('🔴 You have been disconnected. Chat has been cleared.', 'sys');
}

// Connect to server
function connect() {
  if (ws && ws.readyState === WebSocket.OPEN) return;
  const url = urlInput.value.trim();
  ws = new WebSocket(url);

  ws.addEventListener('open', () => {
    addLine('✅ Connected to ' + url, 'sys');
    connectBtn.disabled = true;
    disconnectBtn.disabled = false;
  });

  ws.addEventListener('message', (ev) => {
    addLine(ev.data, 'other');
  });

  ws.addEventListener('close', () => {
    resetUI();
  });

  ws.addEventListener('error', () => {
    addLine('❌ WebSocket error', 'sys');
  });
}

// Disconnect cleanly
function disconnect() {
  if (ws && ws.readyState === WebSocket.OPEN) {
    const username = nameInput.value.trim() || 'Unknown';
    ws.send('/disconnect');
    addLine(`🔴 "${username}" disconnected from the chat`, 'sys');
  }
  resetUI();
}

// Set username
function setName() {
  const n = nameInput.value.trim();
  if (!n) return;
  if (ws && ws.readyState === WebSocket.OPEN) {
    ws.send('/name ' + n);
  } else {
    addLine('❌ Not connected', 'sys');
  }
}

// Send message
function sendMessage(e) {
  e.preventDefault();
  const text = input.value.trim();
  if (!text) return;
  if (ws && ws.readyState === WebSocket.OPEN) {
    ws.send(text);
    addLine(text, 'me');
    input.value = '';
  } else {
    addLine('❌ Not connected', 'sys');
  }
}

// Event bindings
connectBtn.addEventListener('click', connect);
disconnectBtn.addEventListener('click', disconnect);
setNameBtn.addEventListener('click', setName);
form.addEventListener('submit', sendMessage);
