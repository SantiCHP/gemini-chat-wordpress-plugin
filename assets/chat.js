// Incluye marked.js si no está ya incluido
if (typeof marked === 'undefined') {
    var script = document.createElement('script');
    script.src = 'https://cdn.jsdelivr.net/npm/marked/marked.min.js';
    script.onload = function() { window.markedReady = true; };
    document.head.appendChild(script);
}

jQuery(document).ready(function($) {
    // Mostrar el chat al hacer clic en el bocadillo
    $('#gemini-chat-bubble').on('click', function() {
        $('#gemini-chat-window').show();
        $(this).hide();
    });

    // Cerrar el chat
    $('#gemini-chat-close').on('click', function() {
        $('#gemini-chat-window').hide();
        $('#gemini-chat-bubble').show();
    });

    // Enviar mensaje
    $('#gemini-chat-form').on('submit', function(e) {
        e.preventDefault();
        let input = $('#gemini-chat-input');
        let message = input.val().trim();
        if (!message) return;

        // Mostrar mensaje del usuario
        $('#gemini-chat-messages').append('<div class="gemini-chat-user">' + $('<div>').text(message).html() + '</div>');
        input.val('');

        // Mostrar "pensando..."
        $('#gemini-chat-messages').append('<div class="gemini-chat-bot gemini-chat-typing">Gemini está pensando...</div>');
        $('#gemini-chat-messages').scrollTop($('#gemini-chat-messages')[0].scrollHeight);

        // AJAX a WordPress
        $.post(geminiChat.ajax_url, {
            action: 'gemini_chat_ask',
            nonce: geminiChat.nonce,
            message: message
        }, function(response) {
            $('.gemini-chat-typing').remove();
            if (response.success) {
                // Renderiza la respuesta como Markdown si marked.js está disponible
                let html = response.data;
                if (typeof marked !== 'undefined') {
                    html = marked.parse(response.data);
                } else {
                    html = $('<div>').text(response.data).html();
                }
                $('#gemini-chat-messages').append('<div class="gemini-chat-bot"><div class="gemini-chat-bot-content">' + html + '</div></div>');
            } else {
                $('#gemini-chat-messages').append('<div class="gemini-chat-bot">Error: No se pudo obtener respuesta.</div>');
            }
            $('#gemini-chat-messages').scrollTop($('#gemini-chat-messages')[0].scrollHeight);
        });
    });
}); 