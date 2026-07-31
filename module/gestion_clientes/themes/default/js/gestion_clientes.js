(function ($) {
    'use strict';
    function callbackVisibility(select) {
        var option = select.options[select.selectedIndex],
            required = option && String(option.getAttribute('data-callback')) === '1',
            form = $(select).closest('form'),
            fields = form.find('[data-gc-callback-fields]');
        fields.toggleClass('gc-visible', required);
        fields.find('input').prop('required', required);
    }
    function idempotencyKey() {
        return 'gc-' + new Date().getTime() + '-' + Math.floor(Math.random() * 1000000000);
    }
    function pollAttempt(workspace, count) {
        var url = workspace.attr('data-status-url'), attemptId = workspace.attr('data-attempt-id');
        count = count || 0;
        if (!url || !attemptId || count >= 100) { return; }
        $.ajax({url: url, type: 'GET', data: {attempt_id: attemptId}, dataType: 'json'})
            .done(function (response) {
                if (!response || !response.ok || !response.data) { return; }
                workspace.find('.gc-call-status').text(response.data.technical_state || '');
                if (response.data.ended_at) {
                    $('[data-gc-outcome-form] button[type=submit]').prop('disabled', false);
                } else {
                    window.setTimeout(function () { pollAttempt(workspace, count + 1); }, 3000);
                }
            })
            .fail(function () { window.setTimeout(function () { pollAttempt(workspace, count + 1); }, 3000); });
    }
    $(function () {
        $('[data-gc-workspace]').each(function () { pollAttempt($(this)); });
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
                    status.text(response && response.message ? response.message : 'Solicitud enviada.');
                    if (response && response.ok && response.data && response.data.id) {
                        $('[data-gc-workspace]').attr('data-attempt-id', response.data.id);
                        $('[data-gc-outcome-form] input[name=attempt_id]').val(response.data.id);
                        window.setTimeout(function () { pollAttempt($('[data-gc-workspace]').first()); }, 3000);
                    }
                    if (!response || !response.ok) { form.find('button').prop('disabled', false); }
                })
                .fail(function () { status.text('No se pudo iniciar la llamada. Intente nuevamente.'); form.find('button').prop('disabled', false); });
        });
        $('[data-gc-outcome-form]').on('submit', function (event) {
            var form = $(this);
            if (!form.attr('action')) { return; }
            event.preventDefault();
            form.find('button').prop('disabled', true);
            $.ajax({url: form.attr('action'), type: 'POST', data: form.serialize(), dataType: 'json'})
                .done(function (response) {
                    if (response && response.ok) { window.location.reload(); return; }
                    window.alert(response && response.message ? response.message : 'No se pudo guardar el resultado.');
                    form.find('button').prop('disabled', false);
                })
                .fail(function () { window.alert('No se pudo guardar el resultado.'); form.find('button').prop('disabled', false); });
        });
    });
}(window.jQuery));
