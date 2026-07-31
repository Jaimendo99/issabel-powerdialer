(function ($) {
    'use strict';
    function callbackVisibility(select) {
        var option = select.options[select.selectedIndex],
            required = option && String(option.getAttribute('data-callback')) === '1',
            form = $(select).closest('form'),
            fields = form.find('[data-gc-callback-fields]');
        fields.toggleClass('gc-visible', required);
        fields.find('input').prop('required', required);
        form.find('textarea[name=note]').prop('required', required);
    }
    function idempotencyKey() {
        return 'gc-' + new Date().getTime() + '-' + Math.floor(Math.random() * 1000000000);
    }
    function errorResponse(xhr) {
        var response = xhr && xhr.responseJSON ? xhr.responseJSON : null;
        if (!response && xhr && xhr.responseText) {
            try { response = $.parseJSON(xhr.responseText); } catch (ignore) {}
        }
        return response;
    }
    function responseText(response, fallback) {
        var parts = [];
        if (response && response.message) { parts.push(response.message); }
        else { parts.push(fallback); }
        if (response && response.code) { parts.push('Código: ' + response.code); }
        if (response && response.request_id) { parts.push('request_id: ' + response.request_id); }
        return parts.join(' · ');
    }
    function setOutcomeRequired(workspace, required) {
        workspace.attr('data-gc-outcome-required', required ? '1' : '0');
    }
    function pollAttempt(workspace, count) {
        var url = workspace.attr('data-status-url'), attemptId = workspace.attr('data-attempt-id');
        count = count || 0;
        if (!url || !attemptId || count >= 100) { return; }
        $.ajax({url: url, type: 'GET', data: {attempt_id: attemptId}, dataType: 'json'})
            .done(function (response) {
                if (!response || !response.ok || !response.data) { return; }
                workspace.find('.gc-call-status').text(response.data.technical_state || '');
                if (response.data.ended_at && !response.data.business_outcome_id) {
                    $('[data-gc-outcome-form] button[type=submit]').prop('disabled', false);
                    $('[data-gc-call-form] button[type=submit]').prop('disabled', true);
                    setOutcomeRequired(workspace, true);
                } else if (!response.data.ended_at) {
                    window.setTimeout(function () { pollAttempt(workspace, count + 1); }, 3000);
                }
            })
            .fail(function () { window.setTimeout(function () { pollAttempt(workspace, count + 1); }, 3000); });
    }
    $(function () {
        var workspace = $('[data-gc-workspace]').first();
        $('[data-gc-workspace]').each(function () { pollAttempt($(this)); });
        $(window).on('beforeunload', function (event) {
            if (!workspace.length || workspace.attr('data-gc-outcome-required') !== '1') { return; }
            var message = 'Debe guardar el resultado pendiente antes de salir.';
            event.returnValue = message;
            return message;
        });
        $('[data-gc-outcome]').each(function () { callbackVisibility(this); }).on('change', function () { callbackVisibility(this); });
        $('form').on('submit', function () {
            var field = $(this).find('input[name=idempotency_key]');
            if (field.length && !field.val()) { field.val(idempotencyKey()); }
        });
        $('[data-gc-call-form]').on('submit', function (event) {
            var form = $(this), status = form.find('.gc-call-status');
            if (!window.FormData || !form.attr('action')) { return; }
            event.preventDefault();
            form.find('button').prop('disabled', true);
            status.text('Procesando…');
            $.ajax({url: form.attr('action'), type: 'POST', data: form.serialize(), dataType: 'json'})
                .done(function (response) {
                    status.text(responseText(response, 'Solicitud enviada.'));
                    if (response && response.ok && response.data && response.data.id) {
                        $('[data-gc-workspace]').attr('data-attempt-id', response.data.id);
                        $('[data-gc-outcome-form] input[name=attempt_id]').val(response.data.id);
                        window.setTimeout(function () { pollAttempt($('[data-gc-workspace]').first()); }, 3000);
                    }
                    if (!response || !response.ok) {
                        if (response && response.code === 'OUTCOME_REQUIRED_BEFORE_CALL') {
                            setOutcomeRequired(workspace, true);
                            form.find('button').prop('disabled', true);
                        } else if (response && (response.code === 'ACTIVE_ATTEMPT_EXISTS' || response.code === 'AMI_RESULT_UNKNOWN' || response.code === 'AMI_ORIGINATE_FAILED')) {
                            form.find('button').prop('disabled', true);
                        } else {
                            form.find('button').prop('disabled', false);
                        }
                    }
                })
                .fail(function (xhr) {
                    var response = errorResponse(xhr);
                    status.text(responseText(response, 'No se pudo confirmar el estado de la llamada. Recargue la página antes de intentar nuevamente.'));
                    if (response && response.code === 'OUTCOME_REQUIRED_BEFORE_CALL') { setOutcomeRequired(workspace, true); }
                    form.find('button').prop('disabled', true);
                });
        });
        $('[data-gc-outcome-form]').on('submit', function (event) {
            var form = $(this);
            if (!form.attr('action')) { return; }
            event.preventDefault();
            form.find('button').prop('disabled', true);
            $.ajax({url: form.attr('action'), type: 'POST', data: form.serialize(), dataType: 'json'})
                .done(function (response) {
                    if (response && response.ok) { setOutcomeRequired(workspace, false); window.location.reload(); return; }
                    window.alert(responseText(response, 'No se pudo guardar el resultado.'));
                    form.find('button').prop('disabled', false);
                })
                .fail(function (xhr) { window.alert(responseText(errorResponse(xhr), 'No se pudo guardar el resultado.')); form.find('button').prop('disabled', false); });
        });
    });
}(window.jQuery));
