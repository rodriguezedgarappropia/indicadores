<?php
if (!defined('ABSPATH')) {
    exit;
}

class GF_Task_Reports {
    private static $instance = null;
    private $nonce;
    
    private function __construct() {
        add_action('init', array($this, 'init'));
        add_shortcode('gravityflow_report', array($this, 'render_report'));
        
        // Agregar el hook de AJAX con logging
        error_log('Registrando hook de AJAX para get_workflow_stats');
        add_action('wp_ajax_get_workflow_stats', array($this, 'handle_workflow_stats'));
        add_action('wp_ajax_nopriv_get_workflow_stats', array($this, 'handle_workflow_stats'));

        // Generar nonce al construir la clase
        $this->nonce = wp_create_nonce('workflow_stats_nonce');
        error_log('Nonce generado: ' . $this->nonce);
    }
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function init() {
        // Cargar traducciones
        load_plugin_textdomain('gf-task-reports', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    private function enqueue_report_assets() {
        static $assets_loaded = false;

        // Si ya se cargaron los assets, no los cargamos de nuevo
        if ($assets_loaded) {
            return;
        }

        wp_enqueue_style(
            'gf-task-reports', 
            GFTR_PLUGIN_URL . 'assets/css/style.css', 
            array(), 
            GFTR_VERSION
        );

        wp_enqueue_script(
            'google-charts', 
            'https://www.gstatic.com/charts/loader.js', 
            array(), 
            null
        );

        wp_enqueue_script(
            'gf-task-reports', 
            GFTR_PLUGIN_URL . 'assets/js/reports.js', 
            array('jquery', 'google-charts'), 
            GFTR_VERSION, 
            true
        );
        
        wp_localize_script('gf-task-reports', 'gfTaskReports', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => $this->nonce
        ));

        $assets_loaded = true;
    }
    
    public function render_report($atts) {
        // Verificar si GravityFlow está activo
        if (!class_exists('Gravity_Flow')) {
            return '<div class="error">Este plugin requiere que GravityFlow esté instalado y activado.</div>';
        }

        // Cargar los assets necesarios
        $this->enqueue_report_assets();
        
        // Obtener todos los formularios para el filtro
        $forms = GFAPI::get_forms();
        
        // Construir los filtros HTML
        $output = '<div class="gravityflow-reports">';  // Añadido contenedor principal
        $output .= '<div class="gravityflow-report-filters">';
        
        // Filtro de período
        $output .= '<select id="period-filter" class="gravityflow-filter">
            <option value="12">Últimos 12 meses</option>
            <option value="6">Últimos 6 meses</option>
            <option value="3">Últimos 3 meses</option>
            <option value="1">Último mes</option>
        </select>';
        
        // Filtro de formulario
        $output .= '<select id="form-filter" class="gravityflow-filter">
            <option value="">Seleccione un formulario</option>';
        foreach ($forms as $form) {
            $output .= '<option value="' . esc_attr($form['id']) . '">' . esc_html($form['title']) . '</option>';
        }
        $output .= '</select>';
        
        // Filtro de tipo (reemplaza el filtro de usuario)
        $output .= '<select id="type-filter" class="gravityflow-filter">
            <option value="">Seleccione tipo</option>
            <option value="encargado">Encargado</option>
        </select>';
        
        $output .= '</div>';
        
        // Spinner de carga
        $output .= '<div id="loading-spinner" class="gravityflow-spinner" style="display: none;">
            <div class="spinner-content">
                <div class="spinner"></div>
                <p>Analizando datos...</p>
            </div>
        </div>';
        
        // Contenedor para los gráficos con estilo grid
        $output .= '<div id="charts_container" style="display: flex; flex-wrap: wrap; justify-content: space-between; width: 100%;"></div>';
        
        // Debug info
        if (WP_DEBUG) {
            $output .= '<div style="display:none;" id="debug-info" data-nonce="' . esc_attr($this->nonce) . '"></div>';
        }
        
        $output .= '</div>';
        
        return $output;
    }

    public function handle_workflow_stats() {
        error_log('=== EJECUTANDO handle_workflow_stats ===');
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'workflow_stats_nonce')) {
            wp_send_json_error('Nonce inválido', 403);
            return;
        }

        $period = isset($_POST['period']) ? intval($_POST['period']) : 12;
        $form_id = isset($_POST['form_id']) ? intval($_POST['form_id']) : 0;
        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';

        if (empty($form_id) || $type !== 'encargado') {
            wp_send_json_error('Parámetros inválidos');
            return;
        }

        // Obtener estadísticas para todos los usuarios del formulario
        $stats = $this->get_workflow_stats($period, $form_id);
        wp_send_json_success($stats);
    }

    private function get_workflow_stats($period = 12, $form_id = '') {
        global $wpdb;
        
        if (empty($form_id)) {
            return array();
        }

        $date_limit = date('Y-m-d H:i:s', strtotime("-{$period} months"));

        // Consulta para obtener estadísticas de todos los usuarios que han interactuado con el formulario
        $query = $wpdb->prepare("
            SELECT 
                al.assignee_id as user_id,
                u.display_name,
                SUM(CASE WHEN al.log_value = 'complete' THEN 1 ELSE 0 END) as total_completed,
                SUM(CASE WHEN al.log_value = 'approved' THEN 1 ELSE 0 END) as total_approved,
                AVG(al.duration / 3600) as avg_duration
            FROM {$wpdb->prefix}gravityflow_activity_log al
            LEFT JOIN {$wpdb->users} u ON al.assignee_id = u.ID
            WHERE 
                al.date_created >= %s
                AND al.log_value IN ('complete', 'approved')
                AND al.assignee_type = 'user_id'
                AND al.form_id = %d
            GROUP BY 
                al.assignee_id,
                u.display_name
            ORDER BY 
                u.display_name
        ", $date_limit, $form_id);

        $results = $wpdb->get_results($query);

        if ($wpdb->last_error) {
            error_log('Error en la consulta SQL: ' . $wpdb->last_error);
            return array();
        }

        return $results;
    }
} 