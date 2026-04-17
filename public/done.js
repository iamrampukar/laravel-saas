let sessionId = null;
initialize();

async function initialize() {
  const urlParams = new URLSearchParams(window.location.search);
  sessionId = urlParams.get('session_id');
  const accountId = localStorage.getItem('accountId');
  const dashboardAnchor = document.getElementById('dashboard');
  dashboardAnchor.href = `https://dashboard.stripe.com/${accountId}`;
  const sessionIdInput = document.getElementById('session_id');
  sessionIdInput.value = sessionId;

}