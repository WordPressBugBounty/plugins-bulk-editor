/**
 * WOLF - two-factor MCP connection.
 *
 * The two buttons under the "Two-factor connection" setting. Both work over
 * AJAX and update the panel in place: no page reload, and pressing Enter in
 * the token field must not submit the surrounding settings form either.
 */
(function ($) {

    'use strict';

    if (typeof wpbeMcpConnection === 'undefined') {
        return;
    }

    /**
     * Redraws the state line: the text, and the Disconnect button while an
     * assistant is connected. Built from text, never html - the line comes
     * back from the server and is shown as it is.
     */
    function draw_state($panel, text, connected) {

        var $state = $panel.find('.wpbe-mcp-connection-state');

        $state.empty();
        $state.append($('<span class="wpbe-mcp-state-text">').text(text));

        if (connected) {
            $state.append(' ');
            $state.append(
                    $('<button type="button" class="button wpbe-mcp-drop">').text(wpbeMcpConnection.disconnect)
                    );
        }
    }

    function send(action, data, $panel) {

        var $msg = $panel.find('.wpbe-mcp-message');
        var $buttons = $panel.find('button');

        $msg.text(wpbeMcpConnection.working);
        $buttons.prop('disabled', true);

        return $.post(
                wpbeMcpConnection.ajaxurl,
                $.extend({action: action, nonce: wpbeMcpConnection.nonce}, data)
                ).done(function (answer) {

            var body = (answer && answer.data) ? answer.data : {};

            $msg.text(body.message ? body.message : wpbeMcpConnection.failed);

            if (answer && answer.success) {

                if (typeof body.state !== 'undefined') {
                    draw_state($panel, body.state, !!body.connected);
                }

                // a confirmed token has done its job in the field
                if (body.connected) {
                    $panel.find('.wpbe-mcp-token').val('');
                }
            }
        }).fail(function () {
            $msg.text(wpbeMcpConnection.failed);
        }).always(function () {
            // the buttons drawn by draw_state are new and enabled already;
            // this re-enables the confirm button that stayed in place
            $panel.find('button').prop('disabled', false);
        });
    }

    $(document).on('click', '.wpbe-mcp-connection .wpbe-mcp-confirm', function (e) {

        e.preventDefault();

        var $panel = $(this).closest('.wpbe-mcp-connection');
        var token = $.trim($panel.find('.wpbe-mcp-token').val());

        send('wpbe_mcp_confirm_connection', {token: token}, $panel);
    });

    $(document).on('click', '.wpbe-mcp-connection .wpbe-mcp-drop', function (e) {

        e.preventDefault();

        send('wpbe_mcp_drop_connection', {}, $(this).closest('.wpbe-mcp-connection'));
    });

    // Enter in the token field confirms the token instead of submitting the
    // settings form around it
    $(document).on('keydown', '.wpbe-mcp-connection .wpbe-mcp-token', function (e) {

        if (13 === e.keyCode) {
            e.preventDefault();
            $(this).closest('.wpbe-mcp-connection').find('.wpbe-mcp-confirm').trigger('click');
        }
    });

})(jQuery);