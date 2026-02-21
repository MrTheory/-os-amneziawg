<script>
    $( document ).ready(function() {
        mapDataToFormUI({'frm_general_settings': "/api/amneziawg/general/get"}).done(function(data){
            formatTokenizersUI();
            $('.selectpicker').selectpicker('refresh');
        });

        $("#dialogEditInstance").UIBootgrid({
            search:  '/api/amneziawg/instance/search_instance',
            get:     '/api/amneziawg/instance/get_instance/',
            set:     '/api/amneziawg/instance/set_instance/',
            add:     '/api/amneziawg/instance/add_instance/',
            del:     '/api/amneziawg/instance/del_instance/',
            toggle:  '/api/amneziawg/instance/toggle_instance/'
        });

        $("#reconfigureAct").SimpleActionButton({
            onPreAction: function() {
                const dfObj = new $.Deferred();
                saveFormToEndpoint("/api/amneziawg/general/set", 'frm_general_settings', function(){
                    dfObj.resolve();
                });
                return dfObj;
            }
        });

        // Keypair generation button — appended next to Private Key label
        $("#control_label_instance\\.private_key").append(
            $('<span class="pull-right"><button id="keygen" type="button" class="btn btn-secondary btn-xs" title="{{ lang._("Generate keypair") }}" data-toggle="tooltip"><i class="fa fa-fw fa-gear"></i></button></span>')
        );

        $("#keygen").click(function(){
            ajaxGet("/api/amneziawg/instance/gen_key_pair", {}, function(data, status){
                if (data.status && data.status === 'ok') {
                    $("#instance\\.private_key").val(data.private_key);
                }
            });
        });

        // Import .conf parser
        $("#importParseBtn").click(function(){
            ajaxCall("/api/amneziawg/import/parse", {config: $("#importConfigText").val()}, function(data){
                if (data.status === 'ok') {
                    var fields = ['private_key','address','dns','mtu',
                                  'jc','jmin','jmax','s1','s2','h1','h2','h3','h4',
                                  'peer_public_key','peer_preshared_key','peer_endpoint',
                                  'peer_allowed_ips','peer_persistent_keepalive'];
                    fields.forEach(function(f){
                        if (data[f] !== undefined && data[f] !== '') {
                            $("#instance\\." + f).val(data[f]);
                        }
                    });
                    $("#importModal").modal('hide');
                    $("#dialog_dialogEditInstance").modal('show');
                } else {
                    alert(data.message || "{{ lang._('Parse error') }}");
                }
            });
        });

        // Tab state via URL hash
        if (window.location.hash !== "") {
            $('a[href="' + window.location.hash + '"]').click();
        }
        $('.nav-tabs a').on('shown.bs.tab', function (e) {
            history.pushState(null, null, e.target.hash);
        });
    });
</script>

<ul class="nav nav-tabs" data-tabs="tabs" id="maintabs">
    <li class="active"><a data-toggle="tab" href="#instances">{{ lang._('Instances') }}</a></li>
    <li><a data-toggle="tab" href="#general">{{ lang._('General') }}</a></li>
</ul>

<div class="tab-content content-box">
    <div id="instances" class="tab-pane fade in active">
        <div style="padding: 8px 15px;">
            <button class="btn btn-sm btn-default" data-toggle="modal" data-target="#importModal">
                <i class="fa fa-upload"></i> {{ lang._('Import .conf') }}
            </button>
        </div>
        {{ partial('layout_partials/base_bootgrid_table', formGridInstance) }}
    </div>
    <div id="general" class="tab-pane fade in">
        {{ partial("layout_partials/base_form", ['fields': generalForm, 'id': 'frm_general_settings']) }}
    </div>
</div>

{{ partial('layout_partials/base_apply_button', {'data_endpoint': '/api/amneziawg/service/reconfigure'}) }}
{{ partial("layout_partials/base_dialog", ['fields': formDialogInstance, 'id': formGridInstance['edit_dialog_id'], 'label': lang._('Edit AmneziaWG Instance')]) }}

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
                    placeholder="[Interface]&#10;PrivateKey = ...&#10;Address = 10.8.1.2/24&#10;Jc = 4&#10;Jmin = 8&#10;Jmax = 183&#10;S1 = 34&#10;S2 = 45&#10;H1 = ...&#10;H2 = ...&#10;H3 = ...&#10;H4 = ...&#10;&#10;[Peer]&#10;PublicKey = ...&#10;PresharedKey = ...&#10;Endpoint = 1.2.3.4:51820&#10;AllowedIPs = 0.0.0.0/0&#10;PersistentKeepalive = 25"></textarea>
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
