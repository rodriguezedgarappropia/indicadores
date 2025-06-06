<?php
/*
Plugin Name: GravityFlow Task Reports
Plugin URI: 
Description: Plugin para generar reportes de tareas completadas en GravityFlow
Version: 1.0
Author: Tu Nombre
Author URI: 
License: GPL2
*/

// Evitar acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

// Definir constantes del plugin
define('GFTR_VERSION', '1.0');
define('GFTR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GFTR_PLUGIN_URL', plugin_dir_url(__FILE__));

// Incluir archivos necesarios
require_once GFTR_PLUGIN_DIR . 'includes/class-gf-task-reports.php';

// Inicializar el plugin
function gf_task_reports_init() {
    // Verificar si GravityFlow está activo
    if (!class_exists('Gravity_Flow')) {
        if (is_multisite()) {
            // Para multisitio, verificar si está activado en la red
            if (is_plugin_active_for_network('gravityflow/gravityflow.php')) {
                GF_Task_Reports::get_instance();
            } else {
                add_action('network_admin_notices', 'gf_task_reports_admin_notice');
                add_action('admin_notices', 'gf_task_reports_admin_notice');
            }
        } else {
            // Para instalación normal
            add_action('admin_notices', 'gf_task_reports_admin_notice');
        }
        return;
    }
    
    // Inicializar el plugin
    GF_Task_Reports::get_instance();
}
add_action('plugins_loaded', 'gf_task_reports_init');

// Mensaje de error si GravityFlow no está instalado
function gf_task_reports_admin_notice() {
    ?>
    <div class="error">
        <p><?php _e('GravityFlow Task Reports requiere que GravityFlow esté instalado y activado.', 'gf-task-reports'); ?></p>
    </div>
    <?php
}

// Activación del plugin
register_activation_hook(__FILE__, 'gf_task_reports_activate');
function gf_task_reports_activate() {
    // Verificar versión mínima de WordPress
    if (version_compare(get_bloginfo('version'), '5.0', '<')) {
        wp_die('Este plugin requiere WordPress versión 5.0 o superior.');
    }
    
    // Verificar si GravityFlow está instalado
    if (!class_exists('Gravity_Flow')) {
        wp_die('Este plugin requiere que GravityFlow esté instalado y activado.');
    }

    // Si es multisitio, verificar permisos de red
    if (is_multisite() && !current_user_can('manage_network_plugins')) {
        wp_die('Lo sentimos, pero no tienes permisos suficientes para activar plugins en la red.');
    }
}

// Soporte para activación en red
if (is_multisite()) {
    register_activation_hook(__FILE__, 'gf_task_reports_network_activate');
    add_action('wpmu_new_blog', 'gf_task_reports_new_site');
}

/**
 * Activación del plugin en red
 */
function gf_task_reports_network_activate($networkwide) {
    if ($networkwide) {
        global $wpdb;
        $blog_ids = $wpdb->get_col("SELECT blog_id FROM $wpdb->blogs");
        foreach ($blog_ids as $blog_id) {
            switch_to_blog($blog_id);
            gf_task_reports_activate();
            restore_current_blog();
        }
    } else {
        gf_task_reports_activate();
    }
}

/**
 * Manejo de nuevo sitio en la red
 */
function gf_task_reports_new_site($blog_id) {
    if (is_plugin_active_for_network('gravityflow-task-reports/gravityflow-task-reports.php')) {
        switch_to_blog($blog_id);
        gf_task_reports_activate();
        restore_current_blog();
    }
} 