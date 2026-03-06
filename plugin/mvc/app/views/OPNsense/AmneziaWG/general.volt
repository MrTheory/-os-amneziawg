<script>
    $(document).ready(function () {

        // ── Load forms ────────────────────────────────────────────────
        mapDataToFormUI({'frm_general_settings': "/api/amneziawg/general/get"}).done(function () {
            formatTokenizersUI();
            $('.selectpicker').selectpicker('refresh');
        });

        mapDataToFormUI({'frm_instance_settings': "/api/amneziawg/instance/get"}).done(function () {
            formatTokenizersUI();
            $('.selectpicker').selectpicker('refresh');
        });

        // ── Apply ─────────────────────────────────────────────────────
        $("#reconfigureAct").SimpleActionButton({
            onPreAction: function () {
                _statusPaused = true;
                const dfObj = new $.Deferred();
                saveFormToEndpoint("/api/amneziawg/general/set", 'frm_general_settings', function () {
                    saveFormToEndpoint("/api/amneziawg/instance/set", 'frm_instance_settings', function () {
                        dfObj.resolve();
                    }, true, function () {
                        dfObj.resolve();
                    });
                }, true, function () {
                    dfObj.resolve();
                });
                return dfObj;
            },
            onAction: function (data, status) {
                if (data && data.status === 'disabled') {
                    _statusPaused = false;
                    $('#reconfigureAct_progress').addClass('hidden');
                    $('#reconfigureAct').prop('disabled', false);
                    BootstrapDialog.show({
                        type:    BootstrapDialog.TYPE_INFO,
                        title:   "{{ lang._('AmneziaWG') }}",
                        message: "{{ lang._('AmneziaWG is disabled. Enable it on the General tab and apply again.') }}",
                        buttons: [{ label: "{{ lang._('Close') }}", action: function (d) { d.close(); } }]
                    });
                    return;
                }
                if (data && data.result === 'ok') {
                    $('#reconfigureAct_progress').addClass('hidden');
                    $('#reconfigureAct').prop('disabled', false);
                    setTimeout(function () { _statusPaused = false; updateStatus(); }, 2000);
                } else {
                    _statusPaused = false;
                    BootstrapDialog.show({
                        type:    BootstrapDialog.TYPE_DANGER,
                        title:   "{{ lang._('Error') }}",
                        message: "{{ lang._('Error reconfiguring AmneziaWG service.') }}" +
                                 (data && data.output ? '<br><code>' + data.output + '</code>' : ''),
                        buttons: [{ label: "{{ lang._('Close') }}", action: function (d) { d.close(); } }]
                    });
                }
            }
        });

        // ── Status badge ──────────────────────────────────────────────
        var _statusTimer = null;
        var _statusPaused = false;

        function updateStatus() {
            if (_statusPaused) return;
            ajaxGet("/api/amneziawg/service/tunnel_status", {}, function (data) {
                var running = (data.status === 'ok' && data.tunnels && data.tunnels.length > 0);
                $('#badge_awg')
                    .removeClass('label-success label-danger label-default')
                    .addClass(running ? 'label-success' : 'label-danger')
                    .text('awg: ' + (running ? 'running' : 'stopped'));

                if (!_statusPaused) {
                    $('#btnStart').prop('disabled', running);
                    $('#btnStop').prop('disabled', !running);
                }

                // Extract public key from tunnel details
                if (running) {
                    var details = data.tunnels[0].details || '';
                    var match = details.match(/public key:\s*(\S+)/i);
                    if (match) {
                        $('#awg-pubkey-display').text(match[1]).closest('.awg-pubkey-row').show();
                    }
                }
            });
        }
        updateStatus();
        _statusTimer = setInterval(updateStatus, 10000);

        // ── Start / Stop / Restart ────────────────────────────────────
        function serviceAction(action, confirmMsg) {
            if (confirmMsg && !confirm(confirmMsg)) {
                return;
            }
            // Pause status polling to avoid configd contention
            _statusPaused = true;

            var $btns = $('#btnStart, #btnStop, #btnRestart').prop('disabled', true);
            var $btn = $('#btn' + action.charAt(0).toUpperCase() + action.slice(1));
            var origHtml = $btn.html();
            $btn.html('<i class="fa fa-spinner fa-spin"></i>');

            $.ajax({
                url:      '/api/amneziawg/service/' + action,
                type:     'POST',
                dataType: 'json',
                timeout:  65000,
                success: function (data) {
                    $btn.html(origHtml);
                    if (data.result !== 'ok') {
                        alert('{{ lang._("Action failed:") }} ' + (data.message || 'unknown error'));
                    }
                    setTimeout(function () {
                        _statusPaused = false;
                        updateStatus();
                        $btns.prop('disabled', false);
                    }, 2000);
                },
                error: function (xhr) {
                    $btn.html(origHtml);
                    $btns.prop('disabled', false);
                    _statusPaused = false;
                    if (xhr.statusText === 'timeout') {
                        alert('{{ lang._("Request timed out. Check tunnel status manually.") }}');
                    } else {
                        alert('{{ lang._("HTTP error:") }} ' + xhr.status);
                    }
                }
            });
        }

        $('#btnStart').click(function () {
            serviceAction('start', null);
        });

        $('#btnStop').click(function () {
            serviceAction('stop', '{{ lang._("Stop AmneziaWG? Active tunnel will be terminated.") }}');
        });

        $('#btnRestart').click(function () {
            serviceAction('restart', null);
        });

        // ── Keypair generation ────────────────────────────────────────
        $("#keygen").click(function () {
            ajaxGet("/api/amneziawg/instance/gen_key_pair", {}, function (data) {
                if (data.status && data.status === 'ok') {
                    $('[id="instance.private_key"]').val(data.private_key);
                    if (data.public_key) {
                        $('#awg-pubkey-display').text(data.public_key).closest('.awg-pubkey-row').show();
                    }
                }
            });
        });

        // ── Import .conf parser ───────────────────────────────────────
        $("#importParseBtn").click(function () {
            ajaxCall("/api/amneziawg/import/parse", {config: $("#importConfigText").val()}, function (data) {
                if (data.status === 'ok') {
                    var fields = ['private_key','address','dns','mtu',
                                  'jc','jmin','jmax','s1','s2','h1','h2','h3','h4',
                                  'peer_public_key','peer_preshared_key','peer_endpoint',
                                  'peer_allowed_ips','peer_persistent_keepalive'];
                    fields.forEach(function (f) {
                        if (data[f] !== undefined && data[f] !== '') {
                            $('[id="instance.' + f + '"]').val(data[f]);
                        }
                    });
                    $("#importModal").modal('hide');
                    $('a[href="#instance"]').tab('show');
                } else {
                    alert(data.message || "{{ lang._('Parse error') }}");
                }
            });
        });

        // ── Tab hash ──────────────────────────────────────────────────
        if (window.location.hash !== "") {
            $('a[href="' + window.location.hash + '"]').click();
        }
        $('.nav-tabs a').on('shown.bs.tab', function (e) {
            history.pushState(null, null, e.target.hash);
        });
    });
</script>

<ul class="nav nav-tabs" data-tabs="tabs" id="maintabs">
    <li class="active"><a data-toggle="tab" href="#instance">{{ lang._('Instance') }}</a></li>
    <li><a data-toggle="tab" href="#general">{{ lang._('General') }}</a></li>
</ul>

<div class="tab-content content-box">

    <div id="instance" class="tab-pane fade in active">
        {# Status badge + service control buttons #}
        <div style="padding: 10px 15px 6px; display: flex; flex-wrap: wrap; align-items: center; gap: 6px;">
            <span id="badge_awg" class="label label-default">awg: ...</span>

            <span style="margin-left: 4px; border-left: 1px solid #ddd; padding-left: 8px; display: inline-flex; gap: 4px;">
                <button id="btnStart" class="btn btn-xs btn-success" title="{{ lang._('Start AmneziaWG tunnel') }}">
                    <i class="fa fa-play"></i> {{ lang._('Start') }}
                </button>
                <button id="btnStop" class="btn btn-xs btn-danger" title="{{ lang._('Stop AmneziaWG tunnel') }}">
                    <i class="fa fa-stop"></i> {{ lang._('Stop') }}
                </button>
                <button id="btnRestart" class="btn btn-xs btn-warning" title="{{ lang._('Restart without saving config') }}">
                    <i class="fa fa-refresh"></i> {{ lang._('Restart') }}
                </button>
            </span>
        </div>

        <div style="padding: 0 15px 8px; display: flex; gap: 6px;">
            <button class="btn btn-sm btn-default" data-toggle="modal" data-target="#importModal">
                <i class="fa fa-upload"></i> {{ lang._('Import .conf') }}
            </button>
            <button id="keygen" type="button" class="btn btn-sm btn-default" title="{{ lang._('Generate new keypair') }}">
                <i class="fa fa-gear"></i> {{ lang._('Generate Keypair') }}
            </button>
        </div>

        <!-- Public key display row, hidden until key is available -->
        <div class="awg-pubkey-row" style="display:none; padding: 6px 15px 2px;">
            <div class="form-group">
                <label class="col-md-3 control-label">{{ lang._('Public Key') }}</label>
                <div class="col-md-9">
                    <p class="form-control-static">
                        <code id="awg-pubkey-display" style="word-break:break-all;"></code>
                        <button type="button" class="btn btn-xs btn-default" style="margin-left:8px;"
                            onclick="navigator.clipboard && navigator.clipboard.writeText($('#awg-pubkey-display').text())"
                            title="{{ lang._('Copy to clipboard') }}">
                            <i class="fa fa-clipboard"></i>
                        </button>
                    </p>
                    <span class="help-block">{{ lang._('Derived from your private key. Share this with the server administrator.') }}</span>
                </div>
            </div>
        </div>
        {{ partial("layout_partials/base_form", ['fields': instanceForm, 'id': 'frm_instance_settings']) }}
    </div>

    <div id="general" class="tab-pane fade in">
        {{ partial("layout_partials/base_form", ['fields': generalForm, 'id': 'frm_general_settings']) }}
    </div>

</div>

{{ partial('layout_partials/base_apply_button', {'data_endpoint': '/api/amneziawg/service/reconfigure'}) }}

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                <h4 class="modal-title">
                    <i class="fa fa-upload"></i> {{ lang._('Import AmneziaWG Configuration') }}
                </h4>
            </div>
            <div class="modal-body">
                <p class="text-muted">
                    {{ lang._('Paste the contents of your AmneziaWG client .conf file. All fields will be filled automatically.') }}
                </p>
                <textarea id="importConfigText" class="form-control" rows="18"
                    style="font-family: monospace; font-size: 12px;"
                    placeholder="[Interface]&#10;PrivateKey = ...&#10;Address = 10.8.1.2/24&#10;Jc = 4&#10;...&#10;&#10;[Peer]&#10;PublicKey = ...&#10;Endpoint = 1.2.3.4:51820&#10;AllowedIPs = 0.0.0.0/0"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">{{ lang._('Cancel') }}</button>
                <button type="button" class="btn btn-primary" id="importParseBtn">
                    <i class="fa fa-magic"></i> {{ lang._('Parse & Fill') }}
                </button>
            </div>
        </div>
    </div>
</div>
