<?php
declare(strict_types=1);

namespace Anoofy\Woo\Admin;

use Anoofy\Woo\Core\Identity\Action;
use Anoofy\Woo\Services\Services;

final class ConnectionSettings
{
    public static function render(): void
    {
        if (!current_user_can(Action::IntegrationManage->capability())) {
            wp_die(esc_html__('You do not have permission to access this page.', 'anoofy-woo'));
        }

        $conn = Services::engazConnection()->getConnection();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('EngazPRO Integration', 'anoofy-woo') . '</h1>';

        if ($conn === null) {
            echo '<p>' . esc_html__('Paste your connection key from EngazPRO to enable connected mode.', 'anoofy-woo') . '</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('anoofy_connect', '_anoofy_nonce');
            echo '<input type="hidden" name="action" value="anoofy_connect" />';
            echo '<table class="form-table" role="presentation">';
            echo '<tr>';
            echo '<th scope="row"><label for="anoofy_connection_key">' . esc_html__('Connection key', 'anoofy-woo') . '</label></th>';
            echo '<td><textarea id="anoofy_connection_key" name="anoofy_connection_key" rows="4" cols="60" class="large-text code" required></textarea></td>';
            echo '</tr>';
            echo '</table>';
            submit_button(__('Connect to EngazPRO', 'anoofy-woo'), 'primary');
            echo '</form>';
        } else {
            echo '<table class="widefat striped" style="max-width:640px; margin-top:15px"><tbody>';
            echo '<tr>';
            echo '<th scope="row">' . esc_html__('Status', 'anoofy-woo') . '</th>';
            echo '<td><strong style="color:#007017">' . esc_html__('Connected to EngazPRO', 'anoofy-woo') . '</strong></td>';
            echo '</tr>';
            echo '<tr>';
            echo '<th scope="row">' . esc_html__('Key ID (kid)', 'anoofy-woo') . '</th>';
            echo '<td><code>' . esc_html($conn['kid']) . '</code></td>';
            echo '</tr>';
            echo '<tr>';
            echo '<th scope="row">' . esc_html__('Connected at', 'anoofy-woo') . '</th>';
            echo '<td>' . esc_html($conn['connected_at']) . '</td>';
            echo '</tr>';
            echo '</tbody></table>';

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:20px">';
            wp_nonce_field('anoofy_disconnect', '_anoofy_nonce');
            echo '<input type="hidden" name="action" value="anoofy_disconnect" />';
            submit_button(__('Disconnect from EngazPRO', 'anoofy-woo'), 'delete');
            echo '</form>';
        }

        echo '</div>';
    }
}
