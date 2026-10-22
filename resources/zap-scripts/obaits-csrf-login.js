// Obaits generic CSRF-aware form login.
//
// Handles the common pattern: the login form carries a hidden anti-CSRF token
// that changes per request. Plain form-based authentication cannot do this, and
// fails SILENTLY — ZAP posts without the token, the app rejects it, and the scan
// continues unauthenticated while reporting success.
//
// The same pattern covers Laravel (_token), Django (csrfmiddlewaretoken),
// Rails (authenticity_token) and DVWA (user_token) — only the field name differs,
// so it is a parameter.

var HttpRequestHeader = Java.type("org.parosproxy.paros.network.HttpRequestHeader");
var HttpHeader        = Java.type("org.parosproxy.paros.network.HttpHeader");
var URI               = Java.type("org.apache.commons.httpclient.URI");

function authenticate(helper, paramsValues, credentials) {
    var loginUrl  = paramsValues.get("loginUrl");
    var tokenName = paramsValues.get("tokenName");
    var userField = paramsValues.get("usernameField");
    var passField = paramsValues.get("passwordField");
    var extra     = paramsValues.get("extraPostData");   // e.g. Login=Login

    // 1. Fetch the login page to obtain a fresh token.
    var getMsg = helper.prepareMessage();
    getMsg.setRequestHeader(new HttpRequestHeader(HttpRequestHeader.GET, new URI(loginUrl, false), HttpHeader.HTTP11));
    helper.sendAndReceive(getMsg);
    var body = getMsg.getResponseBody().toString();

    // 2. Extract the token value (handles single or double quotes).
    var token = "";
    var re = new RegExp("name=['\"]" + tokenName + "['\"][^>]*value=['\"]([^'\"]+)['\"]", "i");
    var m = re.exec(body);
    if (m === null) {
        // Try the reverse attribute order some frameworks emit.
        re = new RegExp("value=['\"]([^'\"]+)['\"][^>]*name=['\"]" + tokenName + "['\"]", "i");
        m = re.exec(body);
    }
    if (m !== null) { token = m[1]; }

    // 3. POST credentials together with the token.
    var postData = encodeURIComponent(userField) + "=" + encodeURIComponent(credentials.getParam("username"))
                 + "&" + encodeURIComponent(passField) + "=" + encodeURIComponent(credentials.getParam("password"));
    if (token !== "") {
        postData += "&" + encodeURIComponent(tokenName) + "=" + encodeURIComponent(token);
    }
    if (extra !== null && extra !== "") {
        postData += "&" + extra;
    }

    var postMsg = helper.prepareMessage();
    postMsg.setRequestHeader(new HttpRequestHeader(HttpRequestHeader.POST, new URI(loginUrl, false), HttpHeader.HTTP11));
    postMsg.getRequestHeader().setHeader(HttpHeader.CONTENT_TYPE, "application/x-www-form-urlencoded");
    postMsg.setRequestBody(postData);
    postMsg.getRequestHeader().setContentLength(postMsg.getRequestBody().length());
    helper.sendAndReceive(postMsg);

    return postMsg;
}

function getRequiredParamsNames() {
    return ["loginUrl", "tokenName", "usernameField", "passwordField"];
}

function getOptionalParamsNames() {
    return ["extraPostData"];
}

function getCredentialsParamsNames() {
    return ["username", "password"];
}
