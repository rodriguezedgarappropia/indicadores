<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase principal para el manejo de reportes de tareas de GravityFlow
 * Gestiona la visualización y procesamiento de estadísticas de workflows
 */
class GF_Task_Reports {
    private static $instance = null;
    private $nonce;
    
    /**
     * Constructor de la clase
     * Inicializa los hooks necesarios y genera el nonce de seguridad
     */
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
    
    /**
     * Implementa el patrón Singleton para asegurar una única instancia
     * @return GF_Task_Reports Instancia única de la clase
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Inicializa el plugin y carga las traducciones
     */
    public function init() {
        // Cargar traducciones
        load_plugin_textdomain('gf-task-reports', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    /**
     * Carga los archivos CSS y JavaScript necesarios para los reportes
     * Evita cargar los assets múltiples veces usando un flag estático
     */
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

        // Registrar y cargar xlsx-style
        wp_register_script(
            'xlsx-style',
            'https://cdn.jsdelivr.net/npm/xlsx-style@0.8.13/dist/xlsx.full.min.js',
            array(),
            '0.8.13',
            true
        );
        wp_enqueue_script('xlsx-style');

        wp_enqueue_script(
            'gf-task-reports', 
            GFTR_PLUGIN_URL . 'assets/js/reports.js', 
            array('jquery', 'google-charts', 'xlsx-style'), 
            GFTR_VERSION, 
            true
        );
        
        wp_localize_script('gf-task-reports', 'gfTaskReports', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => $this->nonce
        ));

        $assets_loaded = true;
    }
    
    /**
     * Renderiza el formulario de reportes con filtros y contenedor de gráficos
     * @param array $atts Atributos del shortcode
     * @return string HTML del formulario de reportes
     */
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
        $output = '<div class="gravityflow-reports">';
        $output .= '<div class="gravityflow-report-filters">
            <div class="filter-group">';
        
        // Filtro de período
        $output .= '<select id="period-filter" class="gravityflow-filter">
            <option value="today">Hoy</option>
            <option value="last_week">Semana anterior</option>
            <option value="1">Último mes</option>
            <option value="3">Últimos 3 meses</option>
            <option value="6">Últimos 6 meses</option>
            <option value="12">Últimos 12 meses</option>
        </select>';
        
        // Filtro de formulario
        $output .= '<select id="form-filter" class="gravityflow-filter">
            <option value="">Seleccione un formulario</option>';
        foreach ($forms as $form) {
            $output .= '<option value="' . esc_attr($form['id']) . '">' . esc_html($form['title']) . '</option>';
        }
        $output .= '</select>';
        
        // Filtro de tipo
        $output .= '<select id="type-filter" class="gravityflow-filter">
            <option value="">Seleccione tipo</option>
            <option value="encargado">Encargado</option>
            <option value="paso">Paso</option>
        </select>';

        // Botón de exportar
        $output .= '<button id="export-excel" class="gravityflow-export-btn">
            Exportar a Excel
        </button>';
        
        $output .= '</div></div>';

        // Agregar estilos inline
        $output .= '<style>
            .gravityflow-report-filters {
                margin-bottom: 20px;
            }
            .filter-group {
                display: flex;
                align-items: center;
                gap: 10px;
                flex-wrap: nowrap;
            }
            .gravityflow-filter {
                padding: 8px;
                border: 1px solid #ddd;
                border-radius: 4px;
                min-width: 150px;
            }
        </style>';
        
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

    /**
     * Construye la condición SQL para filtrar por fecha según el período seleccionado
     * @param string $period Período seleccionado ('today', 'last_week', '1', '3', '6', '12', 'all')
     * @return string Condición SQL para filtrar por fecha
     */
    private function build_date_condition($period) {
        // Si no hay período seleccionado, no aplicar filtro
        if ($period === 'all') {
            return '';
        }
        
        // Para el día actual
        if ($period === 'today') {
            return " AND DATE(date_created) = CURDATE()";
        }
        
        // Para la semana anterior completa (de lunes a domingo)
        if ($period === 'last_week') {
            return " AND YEARWEEK(date_created, 1) = YEARWEEK(CURDATE() - INTERVAL 1 WEEK, 1)";
        }
        
        // Para períodos en meses (1, 3, 6, 12)
        if (is_numeric($period)) {
            $date_limit = date('Y-m-d H:i:s', strtotime("-{$period} months"));
            return " AND date_created >= '{$date_limit}'";
        }
        
        // Si llega aquí, es un período no válido
        return '';
    }

    /**
     * Obtiene estadísticas de workflow por usuario asignado
     * @param string $period Período para filtrar
     * @param int $form_id ID del formulario
     * @return array Estadísticas de tareas completadas y aprobadas por usuario
     */
    private function get_workflow_stats($period, $form_id) {
        error_log('=== INICIO get_workflow_stats ===');
        error_log("Parámetros recibidos: period={$period}, form_id={$form_id}");
        
        global $wpdb;
        
        $date_condition = $this->build_date_condition($period);
        error_log("Condición de fecha generada: {$date_condition}");
        
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
                al.log_value IN ('complete', 'approved')
                AND al.assignee_type = 'user_id'
                AND al.form_id = %d
                {$date_condition}
            GROUP BY 
                al.assignee_id,
                u.display_name
            ORDER BY 
                u.display_name
        ", $form_id);
        
        error_log("Query a ejecutar: {$query}");
        
        $results = $wpdb->get_results($query);
        
        if ($wpdb->last_error) {
            error_log('Error en la consulta SQL: ' . $wpdb->last_error);
            return array();
        }
        
        error_log('Número de resultados obtenidos: ' . count($results));
        error_log('Resultados: ' . print_r($results, true));
        error_log('=== FIN get_workflow_stats ===');
        
        return $results;
    }

    /**
     * Obtiene estadísticas por paso del workflow
     * @param int $form_id ID del formulario
     * @param string $period Período para filtrar
     * @return array Estadísticas de tareas completadas y duración promedio por paso
     */
    private function get_step_stats($form_id, $period) {
        error_log('=== INICIO get_step_stats ===');
        error_log("Parámetros recibidos: form_id={$form_id}, period={$period}");
        
        global $wpdb;
        
        try {
            // Verificar que la clase existe
            if (!class_exists('Gravity_Flow_API')) {
                error_log('Error: Gravity_Flow_API no existe');
                throw new Exception('GravityFlow no está disponible');
            }
            
            // Obtener los pasos directamente
            error_log('Creando instancia de Gravity_Flow_API...');
            $api = new Gravity_Flow_API($form_id);
            $steps = $api->get_steps();
            
            if (empty($steps)) {
                error_log('Error: No se encontraron pasos en el workflow');
                throw new Exception('No hay pasos configurados en este workflow');
            }
            
            error_log('Número de pasos encontrados: ' . count($steps));
            
            // Construir la condición de fecha
            $date_condition = $this->build_date_condition($period);
            error_log('Condición de fecha: ' . $date_condition);
            
            // Consulta para obtener estadísticas por paso
            $query = $wpdb->prepare("
                SELECT 
                    feed_id,
                    COUNT(*) as total_completed,
                    AVG(duration / 3600) as avg_duration
                FROM {$wpdb->prefix}gravityflow_activity_log
                WHERE log_object = 'step'
                AND log_event = 'ended'
                AND log_value IN ('complete', 'approved')
                AND form_id = %d
                {$date_condition}
                GROUP BY feed_id
            ", $form_id);
            
            error_log('Ejecutando query: ' . $query);
            $results = $wpdb->get_results($query);
            
            if ($wpdb->last_error) {
                error_log('Error en la consulta SQL: ' . $wpdb->last_error);
                throw new Exception('Error al consultar la base de datos: ' . $wpdb->last_error);
            }
            
            error_log('Resultados de la consulta: ' . print_r($results, true));
            
            // Procesar los resultados
            $stats = [];
            foreach ($steps as $step) {
                try {
                    $step_id = $step->get_id();
                    $step_name = $step->get_name();
                    $step_type = $step->get_type();
                    
                    error_log("Procesando paso - ID: {$step_id}, Nombre: {$step_name}, Tipo: {$step_type}");
                    
                    $step_stats = null;
                    foreach ($results as $result) {
                        if ($result->feed_id == $step_id) {
                            $step_stats = $result;
                            break;
                        }
                    }
                    
                    $stats[] = array(
                        'step_id' => $step_id,
                        'display_name' => $step_name,
                        'step_type' => $step_type,
                        'total_completed' => $step_stats ? $step_stats->total_completed : 0,
                        'avg_duration' => $step_stats ? round($step_stats->avg_duration, 1) : 0
                    );
                } catch (Exception $e) {
                    error_log("Error procesando paso {$step_id}: " . $e->getMessage());
                }
            }
            
            error_log('Estadísticas procesadas: ' . print_r($stats, true));
            return $stats;
            
        } catch (Exception $e) {
            error_log('Error en get_step_stats: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            throw $e;
        }
    }

    /**
     * Maneja las peticiones AJAX para obtener estadísticas
     * Procesa los parámetros, obtiene las estadísticas y devuelve JSON
     * @return void Envía respuesta JSON con las estadísticas
     */
    public function handle_workflow_stats() {
        error_log('=== INICIO handle_workflow_stats ===');
        error_log('POST recibido: ' . print_r($_POST, true));
        
        // Verificar nonce
        check_ajax_referer('workflow_stats_nonce', 'nonce');
        
        $form_id = isset($_POST['form_id']) ? intval($_POST['form_id']) : 0;
        $period = isset($_POST['period']) ? sanitize_text_field($_POST['period']) : 'all';
        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';
        
        error_log("Parámetros procesados: form_id={$form_id}, period={$period}, type={$type}");
        
        if (!$form_id) {
            error_log('Error: Formulario no válido');
            wp_send_json_error('Formulario no válido');
            return;
        }
        
        try {
            if ($type === 'paso') {
                error_log('Obteniendo estadísticas de pasos');
                $stats = $this->get_step_stats($form_id, $period);
            } else {
                error_log('Obteniendo estadísticas de workflow');
                $stats = $this->get_workflow_stats($period, $form_id);
            }
            
            error_log('Estadísticas obtenidas: ' . print_r($stats, true));
            wp_send_json_success($stats);
        } catch (Exception $e) {
            error_log('Error en handle_workflow_stats: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            wp_send_json_error($e->getMessage());
        }
        
        error_log('=== FIN handle_workflow_stats ===');
    }
} 