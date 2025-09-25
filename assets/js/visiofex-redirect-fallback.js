(function() {
    'use strict';
    
    function showRedirectFallback(redirectUrl) {
        if (!redirectUrl || document.getElementById('visiofex-redirect-fallback')) {
            return;
        }
        
        var banner = document.createElement('div');
        banner.id = 'visiofex-redirect-fallback';
        banner.style.cssText = 'position:fixed;top:0;left:0;right:0;background:#0073aa;color:#fff;padding:15px;text-align:center;z-index:999999;box-shadow:0 2px 5px rgba(0,0,0,0.2);font-family:system-ui,Arial,sans-serif;font-size:16px;line-height:1.4;';
        
        var message = document.createElement('div');
        message.textContent = 'Click the button below to complete your payment:';
        message.style.marginBottom = '10px';
        
        var link = document.createElement('a');
        link.href = redirectUrl;
        link.target = '_blank';
        link.rel = 'noopener';
        link.textContent = 'Continue to VisioFex Payment';
        link.style.cssText = 'display:inline-block;background:#fff;color:#0073aa;padding:10px 20px;text-decoration:none;border-radius:3px;font-weight:600;margin-right:10px;';
        
        var closeBtn = document.createElement('button');
        closeBtn.textContent = '×';
        closeBtn.style.cssText = 'background:transparent;border:1px solid #fff;color:#fff;padding:8px 12px;border-radius:3px;cursor:pointer;margin-left:10px;';
        closeBtn.onclick = function() {
            banner.remove();
        };
        
        banner.appendChild(message);
        banner.appendChild(link);
        banner.appendChild(closeBtn);
        document.body.appendChild(banner);
        
        console.info('[VisioFex] Automatic redirect failed. Showing fallback link:', redirectUrl);
    }
    
    function extractJsonFromResponse(text) {
        // Method 1: Look for {"order_id" pattern (most reliable)
        var orderIdMatch = text.match(/\{"order_id"[^}]+.*?\}(?=\s*$)/s);
        if (orderIdMatch) {
            try {
                return JSON.parse(orderIdMatch[0]);
            } catch (e) {
                console.warn('[VisioFex] Method 1 failed:', e);
            }
        }
        
        // Method 2: Find last complete JSON object
        var lastBrace = text.lastIndexOf('}');
        if (lastBrace !== -1) {
            // Work backwards to find the matching opening brace
            var braceCount = 0;
            var startPos = lastBrace;
            for (var i = lastBrace; i >= 0; i--) {
                if (text[i] === '}') braceCount++;
                if (text[i] === '{') braceCount--;
                if (braceCount === 0) {
                    startPos = i;
                    break;
                }
            }
            try {
                return JSON.parse(text.substring(startPos, lastBrace + 1));
            } catch (e) {
                console.warn('[VisioFex] Method 2 failed:', e);
            }
        }
        
        // Method 3: Original fallback
        var jsonStart = text.indexOf('{');
        if (jsonStart !== -1) {
            try {
                return JSON.parse(text.substring(jsonStart));
            } catch (e) {
                console.warn('[VisioFex] Method 3 failed:', e);
            }
        }
        
        return null;
    }
    function extractRedirectUrl(responseData) {
        if (!responseData || !responseData.payment_result) {
            return null;
        }

        if (responseData.payment_result.redirect_url) {
            return responseData.payment_result.redirect_url;
        }

        if (Array.isArray(responseData.payment_result.payment_details)) {
            var redirectDetail = responseData.payment_result.payment_details.find(function(detail) {
                return detail.key === 'redirect';
            });
            return redirectDetail ? redirectDetail.value : null;
        }

        return null;
    }

    var originalFetch = window.fetch;
    window.fetch = function() {
        var url = arguments[0] && arguments[0].toString();
        
        return originalFetch.apply(this, arguments).then(function(response) {
            if (url && url.indexOf('/wc/store/v1/checkout') !== -1 && response.ok) {
                response.clone().text().then(function(text) {
                    try {
                        var data = extractJsonFromResponse(text);
                        if (data) {
                            var redirectUrl = extractRedirectUrl(data);
                            if (redirectUrl) {
                                setTimeout(function() {
                                    if (window.location.href.indexOf('checkout') !== -1) {
                                        showRedirectFallback(redirectUrl);
                                    }
                                }, 2000);
                            }
                        }
                    } catch (e) {
                        console.warn('[VisioFex] Error processing checkout response:', e);
                    }
                }).catch(function() {
                    console.warn('[VisioFex] Could not read checkout response body');
                });
            }
            
            return response;
        });
    };
    
    var OriginalXHR = window.XMLHttpRequest;
    var XHROpen = OriginalXHR.prototype.open;
    var XHRSend = OriginalXHR.prototype.send;
    
    OriginalXHR.prototype.open = function(method, url) {
        this._visiofexMonitor = url && url.toString().indexOf('/wc/store/v1/checkout') !== -1;
        return XHROpen.apply(this, arguments);
    };
    
    OriginalXHR.prototype.send = function(data) {
        if (this._visiofexMonitor) {
            this.addEventListener('readystatechange', function() {
                if (this.readyState === 4 && this.status >= 200 && this.status < 300) {
                    try {
                        var responseText = this.responseText || '';
                        var data = extractJsonFromResponse(responseText);
                        if (data) {
                            var redirectUrl = extractRedirectUrl(data);
                            if (redirectUrl) {
                                setTimeout(function() {
                                    if (window.location.href.indexOf('checkout') !== -1) {
                                        showRedirectFallback(redirectUrl);
                                    }
                                }, 2000);
                            }
                        }
                    } catch (e) {
                        console.warn('[VisioFex] Could not parse XHR checkout response:', e);
                    }
                }
            });
        }
        
        return XHRSend.apply(this, arguments);
    };
    
    console.info('[VisioFex] Enhanced redirect fallback system initialized (fetch + XHR monitoring)');
})();
