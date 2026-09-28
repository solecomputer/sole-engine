
          (function() {
            var tabLinks = document.querySelectorAll('#sole_engine_tabs .nav-tab');
            var switchTab = function(tabId) {
              var i;
              for (i = 0; i < tabLinks.length; i++) {
                if (tabLinks[i].getAttribute('data-tab') === tabId) {
                  tabLinks[i].classList.add('nav-tab-active');
                } else {
                  tabLinks[i].classList.remove('nav-tab-active');
                }
              }
              var panels = document.querySelectorAll('.sole-engine-tab-panel');
              for (i = 0; i < panels.length; i++) {
                if (panels[i].id === 'sole_engine_tab_' + tabId) {
                  panels[i].style.display = '';
                } else {
                  panels[i].style.display = 'none';
                }
              }
            };
            for (var t = 0; t < tabLinks.length; t++) {
              (function(link) {
                link.addEventListener('click', function(e) {
                  e.preventDefault();
                  var id = link.getAttribute('data-tab');
                  switchTab(id);
                  window.location.hash = id;
                });
              })(tabLinks[t]);
            }
            var ready = function() {
              var hash = window.location.hash.replace('#', '');
              if (hash === 'console' || hash === 'diagnostics') {
                switchTab(hash);
              }
            };
            if (document.readyState === 'loading') {
              document.addEventListener('DOMContentLoaded', ready);
            } else {
              ready();
            }
          })();
        ;
(function(){var btn=document.getElementById('sole_engine_reset_caller_credits');if(btn){btn.addEventListener('click',function(){if(!confirm('Reset all per-caller credit usage data?'))return;btn.disabled=true;btn.textContent='Resetting...';var nonce=document.getElementById('sole_engine_diagnostics_nonce');jQuery.post(ajaxurl,{action:'sole_engine_reset_caller_credits',_wpnonce:nonce?nonce.value:''},function(resp){btn.disabled=false;btn.textContent='Reset';var el=document.getElementById('sole_engine_caller_credits_result');if(resp.success){var w=document.getElementById('sole_engine_caller_credits_wrap');if(w){w.textContent='No usage recorded.';}if(el){el.textContent='Done.';}}else{if(el){el.textContent='Error.';}}});});}})();;
(function(){var el=document.getElementById('sole_engine_error_log');if(el){el.scrollTop=el.scrollHeight;}var btn=document.getElementById('sole_engine_refresh_error_log');if(btn){btn.addEventListener('click',function(){btn.disabled=true;btn.textContent='Refreshing...';jQuery.post(ajaxurl,{action:'sole_engine_refresh_error_log',_wpnonce:window.soleEngineAdminSettings.errorLogNonce},function(resp){btn.disabled=false;btn.textContent='Refresh';if(resp.success&&typeof resp.data.log==='string'){el.value=resp.data.log;el.scrollTop=el.scrollHeight;}});});}})();;

          (function() {
            var ajaxUrl = window.soleEngineAdminSettings.ajaxUrl;
            var healthButton = document.getElementById('sole_engine_health_check');
            var accountButton = document.getElementById('sole_engine_account_check');
            var accountResult = document.getElementById('sole_engine_account_result');
            var summaryElement = document.getElementById('sole_engine_diagnostics_summary');
            var diagNonceEl = document.getElementById('sole_engine_diagnostics_nonce');
            var diagNonce = diagNonceEl ? diagNonceEl.value : '';

            var postAjax = function(action, onDone) {
              var xhr = new XMLHttpRequest();
              xhr.open('POST', ajaxUrl, true);
              xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
              xhr.onreadystatechange = function() {
                if (xhr.readyState !== 4) {
                  return;
                }
                if (xhr.status < 200 || xhr.status >= 300) {
                  onDone(false, null);
                  return;
                }
                try {
                  var parsed = JSON.parse(xhr.responseText);
                  onDone(true, parsed);
                } catch (e) {
                  onDone(false, null);
                }
              };
              xhr.send('action=' + encodeURIComponent(action) + '&_wpnonce=' + encodeURIComponent(diagNonce));
            };

            var readField = function(id, value) {
              var node = document.getElementById(id);
              if (node) {
                node.textContent = value;
              }
            };

            var applySummary = function(payload) {
              var value = payload && payload.data && typeof payload.data.summary === 'string'
                ? payload.data.summary
                : (summaryElement ? summaryElement.getAttribute('data-fallback-summary') : '');
              if (value) {
                readField('sole_engine_diagnostics_summary', value);
              }
            };

            var applyQuotaDisplay = function(data) {
              if (!data || typeof data.quota_display !== 'string' || typeof data.quota_percent_display !== 'string') {
                return;
              }
              readField('sole_engine_quota_used', data.quota_display);
              readField('sole_engine_quota_usage', data.quota_percent_display);
            };

            if (healthButton) {
              healthButton.addEventListener('click', function() {
                healthButton.disabled = true;
                postAjax('sole_engine_health_check', function(ok, payload) {
                  healthButton.disabled = false;
                  if (!ok || !payload || !payload.success) {
                    readField('sole_engine_health_status', 'error');
                    readField('sole_engine_health_checked_at', 'error');
                    applySummary(payload);
                    return;
                  }
                  readField('sole_engine_health_status', payload.data.status || 'unknown');
                  readField('sole_engine_health_checked_at', payload.data.timestamp || 'unknown');
                  applySummary(payload);
                });
              });
            }
            if (accountButton) {
              accountButton.addEventListener('click', function() {
                accountButton.disabled = true;
                postAjax('sole_engine_account_status', function(ok, payload) {
                  accountButton.disabled = false;
                  if (!ok || !payload || !payload.success) {
                    var errorCode = payload && payload.data ? payload.data.error : '';
                    if (errorCode === 'user_key_invalid') {
                      readField('sole_engine_account_status', 'unavailable — saved key invalid');
                      readField('sole_engine_account_checked_at', 'just now');
                      applyQuotaDisplay(payload.data);
                      if (accountResult) {
                        accountResult.textContent = 'Replace the saved user key with a valid key from your SOLE account, then verify it again.';
                      }
                    } else {
                      readField('sole_engine_account_status', 'error');
                      readField('sole_engine_account_checked_at', 'error');
                      if (accountResult) accountResult.textContent = errorCode || 'Account check failed.';
                    }
                    applySummary(payload);
                    return;
                  }
                  if (accountResult) accountResult.textContent = 'Saved key accepted by API.';
                  readField('sole_engine_account_status', payload.data.status || 'unknown');
                  readField('sole_engine_account_checked_at', payload.data.timestamp || 'unknown');
                  applyQuotaDisplay(payload.data);
                  applySummary(payload);
                });
              });
            }

            if (accountButton) {
              postAjax('sole_engine_account_status', function(ok, payload) {
                if (!ok || !payload || !payload.success) {
                  if (payload && payload.data && payload.data.error === 'user_key_invalid') {
                    readField('sole_engine_account_status', 'unavailable — saved key invalid');
                    readField('sole_engine_account_checked_at', 'just now');
                    applyQuotaDisplay(payload.data);
                  }
                  applySummary(payload);
                  return;
                }
                readField('sole_engine_account_status', payload.data.status || 'unknown');
                readField('sole_engine_account_checked_at', payload.data.timestamp || 'unknown');
                applyQuotaDisplay(payload.data);
                applySummary(payload);
              });
            }

            var runSingleTest = function(category, testId, callback) {
              var prefix = 'sole_engine_test_' + category + '_' + testId;
              var resultEl = document.getElementById(prefix + '_result');
              var detailEl = document.getElementById(prefix + '_detail');
              var btnEl = document.getElementById(prefix + '_run');
              if (resultEl) { resultEl.textContent = 'running...'; resultEl.style.color = ''; }
              if (detailEl) detailEl.textContent = '';
              if (btnEl) btnEl.disabled = true;
              postAjax('sole_engine_test_' + category + '_' + testId, function(ok, payload) {
                if (btnEl) btnEl.disabled = false;
                if (!ok || !payload) {
                  if (resultEl) { resultEl.textContent = 'fail'; resultEl.style.color = '#b32d2e'; }
                  if (detailEl) detailEl.textContent = 'Network error';
                  if (callback) callback();
                  return;
                }
                if (payload.success && payload.data) {
                  var r = payload.data.result || 'unknown';
                  if (resultEl) {
                    resultEl.textContent = r;
                    if (r === 'pass') resultEl.style.color = '#00a32a';
                    else if (r === 'fail') resultEl.style.color = '#b32d2e';
                    else resultEl.style.color = '#666';
                  }
                  if (detailEl) detailEl.textContent = payload.data.detail || '';
                } else {
                  if (resultEl) { resultEl.textContent = 'error'; resultEl.style.color = '#b32d2e'; }
                  if (detailEl) detailEl.textContent = payload.data && payload.data.error ? payload.data.error : 'Unexpected response';
                }
                if (callback) callback();
              });
            };

            var bindTestCategory = function(category) {
              var runAllBtn = document.getElementById('sole_engine_run_all_' + category);
              if (!runAllBtn) return;
              var ids = (runAllBtn.getAttribute('data-test-ids') || '').split(',');
                                                                             
                                                                           
                                        
              var allIds = (runAllBtn.getAttribute('data-all-ids') || runAllBtn.getAttribute('data-test-ids') || '').split(',');
              var i;
              for (i = 0; i < allIds.length; i++) {
                (function(testId) {
                  var btn = document.getElementById('sole_engine_test_' + category + '_' + testId + '_run');
                  if (btn) {
                    btn.addEventListener('click', function() {
                      runSingleTest(category, testId, null);
                    });
                  }
                })(allIds[i]);
              }
              var originalLabel = runAllBtn.textContent;
              runAllBtn.addEventListener('click', function() {
                runAllBtn.disabled = true;
                runAllBtn.textContent = 'Running...';
                var idx = 0;
                var next = function() {
                  if (idx >= ids.length) {
                    runAllBtn.disabled = false;
                    runAllBtn.textContent = originalLabel;
                    return;
                  }
                  runSingleTest(category, ids[idx], function() {
                    idx++;
                    next();
                  });
                };
                next();
              });
            };

            bindTestCategory('internal');
            bindTestCategory('external');

            var toggle = document.getElementById('sole_engine_debug_mode');
            var endpointRow = document.getElementById('sole_engine_endpoint_row');
            if (toggle) {
              var syncDebugRows = function() {
                var show = toggle.checked;
                if (endpointRow) {
                  endpointRow.style.display = show ? '' : 'none';
                }
              };
              toggle.addEventListener('change', syncDebugRows);
              syncDebugRows();
            }

            var purgeButton = document.getElementById('sole_engine_purge_site');
            var purgeSpinner = document.getElementById('sole_engine_purge_spinner');
            var purgeResult = document.getElementById('sole_engine_purge_result');
            if (purgeButton) {
              purgeButton.addEventListener('click', function() {
                if (!confirm('This will delete all stored data for this site from the central API.\n\nSole Engine features will be unavailable for at least 1 minute.\n\nContinue?')) {
                  return;
                }
                purgeButton.disabled = true;
                if (purgeSpinner) {
                  purgeSpinner.style.display = 'inline';
                }
                if (purgeResult) {
                  purgeResult.textContent = '';
                }
                postAjax('sole_engine_purge_site', function(ok, payload) {
                  purgeButton.disabled = false;
                  if (purgeSpinner) {
                    purgeSpinner.style.display = 'none';
                  }
                  if (!ok || !payload) {
                    if (purgeResult) {
                      purgeResult.textContent = 'Error: network failure';
                      purgeResult.style.color = '#b32d2e';
                    }
                    return;
                  }
                  if (!payload.success) {
                    var errMsg = 'Error: ' + (payload.data && payload.data.error ? payload.data.error : 'unknown');
                    if (purgeResult) {
                      purgeResult.textContent = errMsg;
                      purgeResult.style.color = '#b32d2e';
                    }
                    return;
                  }
                  if (purgeResult) {
                    purgeResult.textContent = payload.data.message || 'Purge initiated.';
                    purgeResult.style.color = '#00a32a';
                  }
                });
              });
            }

            var clearCacheButton = document.getElementById('sole_engine_clear_cache');
            var clearCacheSpinner = document.getElementById('sole_engine_clear_cache_spinner');
            var clearCacheResult = document.getElementById('sole_engine_clear_cache_result');
            if (clearCacheButton) {
              clearCacheButton.addEventListener('click', function() {
                if (!confirm('This will delete all cached job IDs, replies, and error entries.\n\nContinue?')) {
                  return;
                }
                clearCacheButton.disabled = true;
                if (clearCacheSpinner) {
                  clearCacheSpinner.style.display = 'inline';
                }
                if (clearCacheResult) {
                  clearCacheResult.textContent = '';
                }
                postAjax('sole_engine_clear_cache', function(ok, payload) {
                  clearCacheButton.disabled = false;
                  if (clearCacheSpinner) {
                    clearCacheSpinner.style.display = 'none';
                  }
                  if (!ok || !payload) {
                    if (clearCacheResult) {
                      clearCacheResult.textContent = 'Error: network failure';
                      clearCacheResult.style.color = '#b32d2e';
                    }
                    return;
                  }
                  if (!payload.success) {
                    var errMsg = 'Error: ' + (payload.data && payload.data.error ? payload.data.error : 'unknown');
                    if (clearCacheResult) {
                      clearCacheResult.textContent = errMsg;
                      clearCacheResult.style.color = '#b32d2e';
                    }
                    return;
                  }
                  if (clearCacheResult) {
                    clearCacheResult.textContent = payload.data.message || 'Cache cleared.';
                    clearCacheResult.style.color = '#00a32a';
                  }
                });
              });
            }

            var indexBatchBtn = document.getElementById('sole_engine_index_batch_btn');
            var indexRefreshBtn = document.getElementById('sole_engine_index_refresh_btn');
            var reindexAllBtn = document.getElementById('sole_engine_reindex_all_btn');
            var indexSpinner = document.getElementById('sole_engine_index_spinner');
            var indexResultEl = document.getElementById('sole_engine_index_result');
            var indexPendingEl = document.getElementById('sole_engine_index_pending');
            var indexLastRunEl = document.getElementById('sole_engine_index_last_run');

            if (indexBatchBtn) {
              indexBatchBtn.addEventListener('click', function() {
                indexBatchBtn.disabled = true;
                if (indexSpinner) indexSpinner.style.display = 'inline';
                if (indexResultEl) indexResultEl.textContent = '';
                postAjax('sole_engine_bulk_index_trigger', function(ok, payload) {
                  indexBatchBtn.disabled = false;
                  if (indexSpinner) indexSpinner.style.display = 'none';
                  if (!ok || !payload || !payload.success) {
                    if (indexResultEl) {
                      indexResultEl.textContent = 'Error running batch.';
                      indexResultEl.style.color = '#b32d2e';
                    }
                    return;
                  }
                  var d = payload.data;
                  if (indexResultEl) {
                    indexResultEl.textContent = 'Processed: ' + d.processed + ' | Remaining: ' + d.pending;
                    indexResultEl.style.color = '#00a32a';
                  }
                  if (indexPendingEl) indexPendingEl.textContent = d.pending;
                });
              });
            }

            if (indexRefreshBtn) {
              indexRefreshBtn.addEventListener('click', function() {
                indexRefreshBtn.disabled = true;
                if (indexResultEl) indexResultEl.textContent = '';
                postAjax('sole_engine_index_status', function(ok, payload) {
                  indexRefreshBtn.disabled = false;
                  if (!ok || !payload || !payload.success) {
                    if (indexResultEl) {
                      indexResultEl.textContent = 'Error fetching status.';
                      indexResultEl.style.color = '#b32d2e';
                    }
                    return;
                  }
                  var d = payload.data;
                  if (indexPendingEl) indexPendingEl.textContent = d.pending;
                  if (indexLastRunEl) {
                    if (d.last_batch_run) {
                      var dt = new Date(parseInt(d.last_batch_run, 10) * 1000);
                      indexLastRunEl.textContent = dt.toISOString().replace('T', ' ').replace(/\.\d+Z$/, ' UTC');
                    } else {
                      indexLastRunEl.textContent = 'Never';
                    }
                  }
                  if (indexResultEl) {
                    indexResultEl.textContent = 'Status refreshed.';
                    indexResultEl.style.color = '#00a32a';
                  }
                });
              });
            }

            if (reindexAllBtn) {
              reindexAllBtn.addEventListener('click', function() {
                if (!window.confirm('Re-embed the entire site from scratch? This re-indexes every published post and consumes engine quota proportional to your content.')) {
                  return;
                }
                reindexAllBtn.disabled = true;
                if (indexSpinner) indexSpinner.style.display = 'inline';
                if (indexResultEl) indexResultEl.textContent = '';
                postAjax('sole_engine_reindex_all_trigger', function(ok, payload) {
                  reindexAllBtn.disabled = false;
                  if (indexSpinner) indexSpinner.style.display = 'none';
                  if (!ok || !payload || !payload.success) {
                    if (indexResultEl) {
                      indexResultEl.textContent = 'Error starting full reindex.';
                      indexResultEl.style.color = '#b32d2e';
                    }
                    return;
                  }
                  var d = payload.data;
                  if (indexResultEl) {
                    indexResultEl.textContent = 'Full reindex queued — ' + d.pending + ' posts pending.';
                    indexResultEl.style.color = '#00a32a';
                  }
                  if (indexPendingEl) indexPendingEl.textContent = d.pending;
                });
              });
            }

            var form = document.querySelector('form[action="options.php"]');
            var saveButton = document.getElementById('sole_engine_save_button');
            var saveNote = document.getElementById('sole_engine_save_note');
            if (!form || !saveButton || !saveNote) {
              return;
            }

            var serialize = function() {
              var items = [];
              var elements = form.elements;
              var i;
              for (i = 0; i < elements.length; i += 1) {
                var el = elements[i];
                if (!el || !el.name) {
                  continue;
                }
                if (el.type === 'checkbox') {
                  items.push(el.name + '=' + (el.checked ? '1' : '0'));
                  continue;
                }
                if (el.type === 'radio') {
                  if (el.checked) {
                    items.push(el.name + '=' + el.value);
                  }
                  continue;
                }
                items.push(el.name + '=' + el.value);
              }
              items.sort();
              return items.join('&');
            };

            var baseline = serialize();
            var updateSaveState = function() {
              var current = serialize();
              if (current === baseline) {
                saveButton.disabled = true;
                saveNote.style.display = 'none';
              } else {
                saveButton.disabled = false;
                saveNote.style.display = 'block';
              }
            };

            updateSaveState();
            form.addEventListener('input', updateSaveState);
            form.addEventListener('change', updateSaveState);

            var consoleNonceEl = document.getElementById('sole_engine_console_nonce');
            var consoleNonce = consoleNonceEl ? consoleNonceEl.value : '';

            var postConsole = function(action, data, onDone) {
              var xhr = new XMLHttpRequest();
              xhr.open('POST', ajaxUrl, true);
              xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
              xhr.onreadystatechange = function() {
                if (xhr.readyState !== 4) return;
                if (xhr.status < 200 || xhr.status >= 300) {
                  onDone(false, null);
                  return;
                }
                try {
                  onDone(true, JSON.parse(xhr.responseText));
                } catch (e) {
                  onDone(false, null);
                }
              };
              var parts = ['action=' + encodeURIComponent(action), '_wpnonce=' + encodeURIComponent(consoleNonce)];
              for (var key in data) {
                if (data.hasOwnProperty(key)) {
                  parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(data[key]));
                }
              }
              xhr.send(parts.join('&'));
            };

            var appendOutput = function(el, line) {
              if (el.value !== '') el.value += '\n';
              el.value += line;
              el.scrollTop = el.scrollHeight;
            };

                                                                             
                                                                              
                                                                            
                                                                          
                                                                               
                                                                               
                                                                               
            var errorTtl = window.soleEngineAdminSettings.errorTtl;

            var errorDesc = {
              config_missing: 'Plugin not configured. Set API endpoint and user key in settings above.',
              user_key_invalid: 'User key rejected by API. Verify it matches your SOLE account.',
              account_disabled: 'Account disabled. Contact SOLE support.',
              quota_exceeded: 'Quota limit reached. Wait for reset or upgrade plan.',
              rate_limited: 'Too many requests. Wait a few seconds.',
              invalid_payload: 'Payload malformed or invalid. Check fields and try again.',
              task_unsupported: 'Task name not recognized by the API.',
              job_not_found: 'Job ID expired or does not exist. Submit a new request.',
              provider_error: 'AI provider failed to process the job. Usually transient — retry shortly.',
              engine_internal: 'Unexpected engine error. Usually transient — retry shortly.',
              api_response_invalid: 'API returned unparseable response. Check network/proxy.',
              network_error: 'Network failure. Check connectivity and endpoint URL.',
              site_purging: 'Site data is being purged. Wait for completion.',
              caller_blocked: 'Caller plugin is on the blocklist. Check Caller Blocklist in settings above.'
            };

            var errorHint = function(code) {
              var ttl = errorTtl[code];
              if (ttl === undefined) ttl = 180;
              var desc = errorDesc[code] || 'Unknown error.';
              var cache = ttl === 0
                ? 'Not cached — retry anytime.'
                : 'Cached for ' + ttl + 's — retry after that.';
              return desc + ' ' + cache;
            };

            var llmPresets = {
              chat: {
                greeting: { label: 'Greeting', payload: 'Hello, how are you?' },
                explain_wp: { label: 'Explain WP', payload: 'Explain what WordPress is in two sentences.' }
              },
              translate: {
                en_fr: { label: 'English to French', payload: 'The weather is nice today', targetLanguage: 'french' },
                auto_en: { label: 'Auto to English', payload: 'Hola, buenos días', targetLanguage: 'english' }
              },
              summary: {
                short_article: {
                  label: 'Short article',
                  payload: 'WordPress is a free and open-source content management system written in PHP and paired with a MySQL or MariaDB database. Features include a plugin architecture and a template system, referred to within WordPress as Themes. WordPress was originally created as a blog-publishing system but has evolved to support other web content types including more traditional mailing lists and forums, media galleries, membership sites, learning management systems, and online stores.'
                }
              }
            };

            var semanticPresets = {
              relevance: {
                similar: { label: 'Similar', textA: 'The weather is sunny and warm today', textB: 'It is a bright and hot day outside' },
                different: { label: 'Different', textA: 'The weather is sunny today', textB: 'I enjoy eating pizza for dinner' }
              },
              search: {
                basic: { label: 'Basic search', query: 'How does WordPress work?' }
              }
            };

            var semanticTaskFields = {
              relevance: ['text_a', 'text_b'],
              search: ['query', 'top_k', 'post_types']
            };
            var allSemanticFields = ['text_a', 'text_b', 'query', 'top_k', 'post_types'];

            var llmTaskFields = {
              chat: [],
              translate: ['targetLanguage', 'sourceLanguage', 'extra'],
              summary: ['size', 'extra']
            };
            var allLlmOptionFields = ['targetLanguage', 'sourceLanguage', 'size', 'extra'];

            var taskSelect = document.getElementById('sole_engine_console_llm_task');
            var presetSelect = document.getElementById('sole_engine_console_llm_preset');

            var syncTaskFields = function() {
              if (!taskSelect) return;
              var task = taskSelect.value;
              var fields = llmTaskFields[task] || [];
              var i;
              for (i = 0; i < allLlmOptionFields.length; i++) {
                var row = document.getElementById('sole_engine_console_llm_row_' + allLlmOptionFields[i]);
                if (row) {
                  row.style.display = fields.indexOf(allLlmOptionFields[i]) !== -1 ? '' : 'none';
                }
              }
              if (presetSelect) {
                presetSelect.innerHTML = '<option value="">-- select preset --</option>';
                var presets = llmPresets[task] || {};
                for (var key in presets) {
                  if (presets.hasOwnProperty(key)) {
                    var opt = document.createElement('option');
                    opt.value = key;
                    opt.textContent = presets[key].label;
                    presetSelect.appendChild(opt);
                  }
                }
              }
            };

            if (taskSelect) {
              taskSelect.addEventListener('change', function() {
                syncTaskFields();
                var payloadEl = document.getElementById('sole_engine_console_llm_payload');
                if (payloadEl) payloadEl.value = '';
                for (var i = 0; i < allLlmOptionFields.length; i++) {
                  var el = document.getElementById('sole_engine_console_llm_' + allLlmOptionFields[i]);
                  if (el) el.value = '';
                }
                if (presetSelect) presetSelect.value = '';
              });
              syncTaskFields();
            }

            if (presetSelect) {
              presetSelect.addEventListener('change', function() {
                if (!taskSelect) return;
                var task = taskSelect.value;
                var key = presetSelect.value;
                if (key === '' || !llmPresets[task] || !llmPresets[task][key]) return;
                var preset = llmPresets[task][key];
                var payloadEl = document.getElementById('sole_engine_console_llm_payload');
                if (payloadEl) payloadEl.value = preset.payload || '';
                for (var i = 0; i < allLlmOptionFields.length; i++) {
                  var el = document.getElementById('sole_engine_console_llm_' + allLlmOptionFields[i]);
                  if (el) el.value = preset[allLlmOptionFields[i]] || '';
                }
              });
            }

            var llmSendBtn = document.getElementById('sole_engine_console_llm_send');
            var llmOutput = document.getElementById('sole_engine_console_llm_output');

            if (llmSendBtn && llmOutput) {
              llmSendBtn.addEventListener('click', function() {
                var task = taskSelect ? taskSelect.value : 'chat';
                var payloadEl = document.getElementById('sole_engine_console_llm_payload');
                var payload = payloadEl ? payloadEl.value : '';
                if (payload.trim() === '') return;

                llmSendBtn.disabled = true;
                llmOutput.value = '';

                var options = {};
                var fields = llmTaskFields[task] || [];
                for (var i = 0; i < fields.length; i++) {
                  var el = document.getElementById('sole_engine_console_llm_' + fields[i]);
                  if (el && el.value.trim() !== '') {
                    options[fields[i]] = el.value.trim();
                  }
                }
                var optionsStr = Object.keys(options).length > 0 ? JSON.stringify(options) : '';

                var summary = payload.length > 60 ? payload.substring(0, 60) + '...' : payload;
                appendOutput(llmOutput, '[Submit] Task: ' + task + ' | Payload: "' + summary + '"');

                postConsole('sole_engine_console_llm_submit', {
                  task: task,
                  payload: payload,
                  options: optionsStr
                }, function(ok, resp) {
                  if (!ok || !resp || !resp.success) {
                    var errMsg = resp && resp.data && resp.data.error ? resp.data.error : 'network_error';
                    appendOutput(llmOutput, '[Ticket] Rejected | Error: ' + errMsg);
                    llmSendBtn.disabled = false;
                    return;
                  }
                  var d = resp.data;
                  if (!d.was_accepted) {
                    appendOutput(llmOutput, '[Ticket] Rejected | Error: ' + (d.error || 'unknown'));
                    appendOutput(llmOutput, '[Info] ' + errorHint(d.error || ''));
                    llmSendBtn.disabled = false;
                    return;
                  }
                  appendOutput(llmOutput, '[Ticket] Accepted | Job ID: ' + d.job_id);

                  var jobId = d.job_id;
                  var pollCount = 0;
                  var maxPolls = 15;
                  var pollInterval = 2000;

                  var poll = function() {
                    pollCount++;
                    postConsole('sole_engine_console_llm_poll', { job_id: jobId }, function(ok2, resp2) {
                      if (!ok2 || !resp2 || !resp2.success) {
                        appendOutput(llmOutput, '[Poll ' + pollCount + '] Error: network failure');
                        llmSendBtn.disabled = false;
                        return;
                      }
                      var r = resp2.data;
                      if (r.is_pending) {
                        appendOutput(llmOutput, '[Poll ' + pollCount + '] Pending...');
                        if (pollCount < maxPolls) {
                          setTimeout(poll, pollInterval);
                        } else {
                          appendOutput(llmOutput, '[Poll ' + pollCount + '] Gave up after ' + maxPolls + ' attempts.');
                          llmSendBtn.disabled = false;
                        }
                        return;
                      }
                      if (r.is_successful) {
                        appendOutput(llmOutput, '[Poll ' + pollCount + '] Success');
                        appendOutput(llmOutput, '[Reply] ' + (r.reply || ''));
                      } else {
                        appendOutput(llmOutput, '[Poll ' + pollCount + '] Failed | Error: ' + (r.error || 'unknown'));
                        appendOutput(llmOutput, '[Info] ' + errorHint(r.error || ''));
                      }
                      llmSendBtn.disabled = false;
                    });
                  };

                  setTimeout(poll, pollInterval);
                });
              });
            }

            var semTaskSelect = document.getElementById('sole_engine_console_semantic_task');
            var semPresetSelect = document.getElementById('sole_engine_console_semantic_preset');
            var semSendBtn = document.getElementById('sole_engine_console_semantic_send');
            var semOutput = document.getElementById('sole_engine_console_semantic_output');

            var syncSemanticFields = function() {
              if (!semTaskSelect) return;
              var task = semTaskSelect.value;
              var fields = semanticTaskFields[task] || [];
              var i;
              for (i = 0; i < allSemanticFields.length; i++) {
                var row = document.getElementById('sole_engine_console_semantic_row_' + allSemanticFields[i]);
                if (row) {
                  row.style.display = fields.indexOf(allSemanticFields[i]) !== -1 ? '' : 'none';
                }
              }
              if (semPresetSelect) {
                semPresetSelect.innerHTML = '<option value="">-- select preset --</option>';
                var presets = semanticPresets[task] || {};
                for (var key in presets) {
                  if (presets.hasOwnProperty(key)) {
                    var opt = document.createElement('option');
                    opt.value = key;
                    opt.textContent = presets[key].label;
                    semPresetSelect.appendChild(opt);
                  }
                }
              }
            };

            if (semTaskSelect) {
              semTaskSelect.addEventListener('change', function() {
                syncSemanticFields();
                for (var i = 0; i < allSemanticFields.length; i++) {
                  var el = document.getElementById('sole_engine_console_semantic_' + allSemanticFields[i]);
                  if (el) el.value = '';
                }
                if (semPresetSelect) semPresetSelect.value = '';
              });
              syncSemanticFields();
            }

            if (semPresetSelect) {
              semPresetSelect.addEventListener('change', function() {
                if (!semTaskSelect) return;
                var task = semTaskSelect.value;
                var key = semPresetSelect.value;
                if (key === '' || !semanticPresets[task] || !semanticPresets[task][key]) return;
                var preset = semanticPresets[task][key];
                var textAEl = document.getElementById('sole_engine_console_semantic_text_a');
                var textBEl = document.getElementById('sole_engine_console_semantic_text_b');
                var queryEl = document.getElementById('sole_engine_console_semantic_query');
                if (textAEl) textAEl.value = preset.textA || '';
                if (textBEl) textBEl.value = preset.textB || '';
                if (queryEl) queryEl.value = preset.query || '';
              });
            }

            if (semSendBtn && semOutput) {
              semSendBtn.addEventListener('click', function() {
                var task = semTaskSelect ? semTaskSelect.value : 'relevance';
                var payload = '';
                var options = {};

                if (task === 'relevance') {
                  var textAEl = document.getElementById('sole_engine_console_semantic_text_a');
                  var textBEl = document.getElementById('sole_engine_console_semantic_text_b');
                  var textA = textAEl ? textAEl.value : '';
                  var textB = textBEl ? textBEl.value : '';
                  if (textA.trim() === '' || textB.trim() === '') return;
                  payload = JSON.stringify({ a: textA, b: textB });
                } else if (task === 'search') {
                  var queryEl = document.getElementById('sole_engine_console_semantic_query');
                  var query = queryEl ? queryEl.value : '';
                  if (query.trim() === '') return;
                                                                   
                                                          
                  var payloadObj = { query: query };
                  var topKEl = document.getElementById('sole_engine_console_semantic_top_k');
                  var postTypesEl = document.getElementById('sole_engine_console_semantic_post_types');
                  if (topKEl && topKEl.value.trim() !== '') {
                    payloadObj.top_k = parseInt(topKEl.value, 10);
                  }
                  payload = JSON.stringify(payloadObj);
                  if (postTypesEl && postTypesEl.value.trim() !== '') {
                    options.post_types = postTypesEl.value.split(',').map(function(s) { return s.trim(); }).filter(function(s) { return s !== ''; });
                  }
                }

                var optionsStr = Object.keys(options).length > 0 ? JSON.stringify(options) : '';

                semSendBtn.disabled = true;
                semOutput.value = '';

                var summary = payload.length > 60 ? payload.substring(0, 60) + '...' : payload;
                appendOutput(semOutput, '[Submit] Task: ' + task + ' | Payload: "' + summary + '"');

                postConsole('sole_engine_console_semantic_submit', {
                  task: task,
                  payload: payload,
                  options: optionsStr
                }, function(ok, resp) {
                  if (!ok || !resp || !resp.success) {
                    var errMsg = resp && resp.data && resp.data.error ? resp.data.error : 'network_error';
                    appendOutput(semOutput, '[Ticket] Rejected | Error: ' + errMsg);
                    semSendBtn.disabled = false;
                    return;
                  }
                  var d = resp.data;
                  if (!d.was_accepted) {
                    appendOutput(semOutput, '[Ticket] Rejected | Error: ' + (d.error || 'unknown'));
                    appendOutput(semOutput, '[Info] ' + errorHint(d.error || ''));
                    semSendBtn.disabled = false;
                    return;
                  }
                  appendOutput(semOutput, '[Ticket] Accepted | Job ID: ' + d.job_id);

                  var jobId = d.job_id;
                  var pollCount = 0;
                  var maxPolls = 15;
                  var pollInterval = 2000;

                  var poll = function() {
                    pollCount++;
                    postConsole('sole_engine_console_semantic_poll', { job_id: jobId }, function(ok2, resp2) {
                      if (!ok2 || !resp2 || !resp2.success) {
                        appendOutput(semOutput, '[Poll ' + pollCount + '] Error: network failure');
                        semSendBtn.disabled = false;
                        return;
                      }
                      var r = resp2.data;
                      if (r.is_pending) {
                        appendOutput(semOutput, '[Poll ' + pollCount + '] Pending...');
                        if (pollCount < maxPolls) {
                          setTimeout(poll, pollInterval);
                        } else {
                          appendOutput(semOutput, '[Poll ' + pollCount + '] Gave up after ' + maxPolls + ' attempts.');
                          semSendBtn.disabled = false;
                        }
                        return;
                      }
                      if (r.is_successful) {
                        appendOutput(semOutput, '[Poll ' + pollCount + '] Success');
                        appendOutput(semOutput, '[Reply] ' + (r.reply || ''));
                      } else {
                        appendOutput(semOutput, '[Poll ' + pollCount + '] Failed | Error: ' + (r.error || 'unknown'));
                        appendOutput(semOutput, '[Info] ' + errorHint(r.error || ''));
                      }
                      semSendBtn.disabled = false;
                    });
                  };

                  setTimeout(poll, pollInterval);
                });
              });
            }

          })();
