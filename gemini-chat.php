<?php
/*
Plugin Name: Gemini Chat
Description: Chat conversacional con Gemini 2.5 Flash, limitado al contenido de esta web.
Version: 1.0
Author: Santiago Castellano
*/

if (!defined('ABSPATH')) exit; // Seguridad: evita acceso directo

// Cargar scripts y estilos
add_action('wp_enqueue_scripts', function() {
    wp_enqueue_style('gemini-chat-css', plugin_dir_url(__FILE__) . 'assets/chat.css');
    wp_enqueue_script('gemini-chat-js', plugin_dir_url(__FILE__) . 'assets/chat.js', ['jquery'], null, true);

    // Pasar datos de WordPress a JS (para AJAX)
    wp_localize_script('gemini-chat-js', 'geminiChat', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('gemini_chat_nonce')
    ]);
});

// Insertar el bocadillo flotante en el footer
add_action('wp_footer', function() {
    ?>
    <div id="gemini-chat-bubble">
        <img src="<?php echo plugins_url('gemini-chat/assets/chat-icon.png'); ?>" alt="Chat">
    </div>
    <div id="gemini-chat-window" style="display:none;">
        <div id="gemini-chat-header">Gemini Chat <span id="gemini-chat-close">&times;</span></div>
        <div id="gemini-chat-messages"></div>
        <form id="gemini-chat-form">
            <input type="text" id="gemini-chat-input" placeholder="Escribe tu pregunta..." autocomplete="off">
            <button type="submit">Enviar</button>
        </form>
    </div>
    <?php
});

// Endpoint AJAX para el chat
add_action('wp_ajax_gemini_chat_ask', 'gemini_chat_ask');
add_action('wp_ajax_nopriv_gemini_chat_ask', 'gemini_chat_ask');

function gemini_chat_ask() {
    check_ajax_referer('gemini_chat_nonce', 'nonce');

    $user_message = sanitize_text_field($_POST['message'] ?? '');

    if (!$user_message) {
        wp_send_json_error('Mensaje vacío');
    }

    // Detectar si el usuario pide saber más sobre un título
    $pattern = '/quiero saber más sobre (.+)/i';
    if (preg_match($pattern, $user_message, $matches)) {
        $titulo = trim($matches[1]);
        $contenido = gemini_chat_get_post_content_by_title($titulo);
        if ($contenido) {
            $prompt = "El usuario quiere saber más sobre la siguiente página/post de este sitio. Usa solo el contenido proporcionado para responder de forma detallada y útil.\n\n";
            $prompt .= "Título: $titulo\n";
            $prompt .= "Contenido:\n$contenido\n\n";
            $prompt .= "Pregunta del usuario: $user_message\nRespuesta:";
        } else {
            $prompt = "No se encontró una página o post con el título exacto '$titulo'. Indica al usuario que revise el título o intente con otro.";
        }
    } else {
        // Contexto inicial: todos los títulos y extractos
        $context = gemini_chat_get_site_context(true); // true = todos los posts/páginas
        $prompt = "Eres un asistente que solo puede responder preguntas relacionadas con el contenido de este sitio web.\n";
        $prompt .= "A continuación tienes una lista de títulos y extractos de todas las páginas y posts publicados.\n";
        $prompt .= "Si la pregunta es general, sugiere el título más relevante. Si el usuario pide saber más sobre un título concreto, indícale que escriba: 'Quiero saber más sobre [título]'.\n";
        $prompt .= "Si la pregunta no está relacionada, responde: 'Solo puedo responder sobre el contenido de esta web.'\n\n";
        $prompt .= "Contenido del sitio:\n" . $context . "\n\n";
        $prompt .= "Pregunta del usuario: $user_message\nRespuesta:";
    }

    // Llamar a la API de Gemini
    $response = gemini_chat_call_gemini($prompt);

    if ($response) {
        wp_send_json_success($response);
    } else {
        wp_send_json_error('No se pudo obtener respuesta de Gemini.');
    }
}

// Modifico esta función para permitir obtener todos los posts/páginas si $all es true
function gemini_chat_get_site_context($all = false) {
    $args = [
        'post_type'      => ['post', 'page'],
        'post_status'    => 'publish',
        'posts_per_page' => $all ? -1 : 10, // -1 para todos
        'orderby'        => 'date',
        'order'          => 'DESC',
    ];
    $query = new WP_Query($args);
    $context = '';
    if ($query->have_posts()) {
        foreach ($query->posts as $post) {
            $context .= "Título: " . $post->post_title . "\n";
            $context .= "Extracto: " . wp_strip_all_tags(get_the_excerpt($post)) . "\n\n";
        }
    }
    wp_reset_postdata();
    return $context;
}

// Nueva función: obtener el contenido completo de un post/página por título
function gemini_chat_get_post_content_by_title($titulo) {
    global $wpdb;
    $post = $wpdb->get_row($wpdb->prepare(
        "SELECT ID, post_content FROM $wpdb->posts WHERE post_title = %s AND post_status = 'publish' AND (post_type = 'post' OR post_type = 'page') LIMIT 1",
        $titulo
    ));
    if ($post) {
        return wp_strip_all_tags(apply_filters('the_content', $post->post_content));
    }
    return false;
}

function gemini_chat_call_gemini($prompt) {
    $api_key = 'TU_API_KEY_AQUI'; // <-- Cambia esto por tu clave real
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . $api_key;

    $data = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ]
    ];

    $args = [
        'body'        => json_encode($data),
        'headers'     => [
            'Content-Type' => 'application/json'
        ],
        'timeout'     => 30,
    ];

    $response = wp_remote_post($url, $args);

    if (is_wp_error($response)) {
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $json = json_decode($body, true);

    if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
        return $json['candidates'][0]['content']['parts'][0]['text'];
    }

    return false;
} 