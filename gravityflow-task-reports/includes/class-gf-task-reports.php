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
    private $table_prefix;
    
    /**
     * Constructor de la clase
     * Inicializa los hooks necesarios y genera el nonce de seguridad
     */
    private function __construct() {
        global $wpdb;
        
        // Obtener el prefijo de tabla correcto para el sitio actual
        $this->table_prefix = $wpdb->get_blog_prefix();
        
        add_action('init', array($this, 'init'));
        add_shortcode('gravityflow_report', array($this, 'render_report'));
        
        // Agregar el hook de AJAX
        add_action('wp_ajax_get_workflow_stats', array($this, 'handle_workflow_stats'));
        add_action('wp_ajax_nopriv_get_workflow_stats', array($this, 'handle_workflow_stats'));

        // Generar nonce al construir la clase
        $this->nonce = wp_create_nonce('workflow_stats_nonce');
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
     * Obtiene el prefijo de tabla correcto para el sitio actual
     * @return string Prefijo de tabla
     */
    private function get_table_prefix() {
        return $this->table_prefix;
    }

    /**
     * Carga los archivos CSS y JavaScript necesarios para los reportes
     * Evita cargar los assets múltiples veces usando un flag estático
     */
    private function enqueue_report_assets() {
        static $assets_loaded = false;

        if ($assets_loaded) {
            return;
        }

        wp_enqueue_style(
            'gf-task-reports', 
            GFTR_PLUGIN_URL . 'assets/css/style.css', 
            array(), 
            GFTR_VERSION
        );

        // Flatpickr CSS
        wp_enqueue_style(
            'flatpickr',
            'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css',
            array(),
            '4.6.13'
        );

        wp_enqueue_script(
            'google-charts', 
            'https://www.gstatic.com/charts/loader.js', 
            array(), 
            null
        );

        // Flatpickr JS
        wp_enqueue_script(
            'flatpickr',
            'https://cdn.jsdelivr.net/npm/flatpickr',
            array('jquery'),
            '4.6.13',
            true
        );
        // Flatpickr español
        wp_enqueue_script(
            'flatpickr-es',
            'https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/es.js',
            array('flatpickr'),
            '4.6.13',
            true
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
            array('jquery', 'google-charts', 'xlsx-style', 'flatpickr', 'flatpickr-es'), 
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
        $output .= '<div class="gravityflow-report-filters-flex">'
            . '<div class="filter-group-left">';
        
        // Filtro de período
        $output .= '<select id="period-filter" class="gravityflow-filter">
            <option value="today">Registros de Hoy</option>
            <option value="last_week">Última Semana Completa</option>
            <option value="last_month">Últimos 30 Días</option>
            <option value="last_3_months">Últimos 90 Días</option>
            <option value="last_6_months">Últimos 180 Días</option>
            <option value="last_year">Últimos 365 Días</option>
            <option value="custom">Personalizado</option>
        </select>';

        // Contenedor para el selector de fechas personalizado (ahora dentro del grupo de filtros)
        $output .= '<div id="custom-date-container" class="custom-date-container" style="display: none; margin: 0; padding: 0; background: none; border: none;">'
            . '<div class="custom-date-wrapper" style="padding:0; background:none; border:none;">'
            . '<input type="text" id="date-range" class="gravityflow-filter" placeholder="Seleccionar rango de fechas">'
            . '</div>'
            . '</div>';

        // Filtro de formulario
        $output .= '<select id="form-filter" class="gravityflow-filter">
            <option value="">Seleccionar formulario</option>';
        foreach ($forms as $form) {
            $output .= '<option value="' . esc_attr($form['id']) . '">' . esc_html($form['title']) . '</option>';
        }
        $output .= '</select>';
        
        // Filtro de tipo
        $output .= '<select id="type-filter" class="gravityflow-filter">
            <option value="">Seleccionar tipo</option>
            <option value="paso">Por Paso</option>
            <option value="encargado">Por Encargado</option>
            <option value="mensual">Por Mes</option>
            <option value="tareas_pendientes_paso">Tareas Pendientes por Paso</option>
        </select>';
        
        // Botón de exportar a la derecha
        $output .= '<div class="filter-group-right">'
            . '<button id="export-excel" class="gravityflow-export-btn">Exportar a Excel</button>'
            . '</div>';
        $output .= '</div>';

        // Ajustar estilos para flexbox y responsividad
        $output .= '<style>
            .gravityflow-report-filters-flex {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 10px;
                flex-wrap: wrap;
                margin-bottom: 20px;
            }
            .filter-group-left {
                display: flex;
                align-items: center;
                gap: 10px;
                flex-wrap: wrap;
            }
            .filter-group-right {
                display: flex;
                align-items: center;
            }
            .gravityflow-filter {
                padding: 8px;
                border: 1px solid #ddd;
                border-radius: 4px;
                min-width: 150px;
                max-width: 250px;
            }
            .custom-date-container {
                display: flex;
                align-items: center;
                margin: 0;
                padding: 0;
                background: none;
                border: none;
            }
            .custom-date-wrapper {
                display: flex;
                gap: 10px;
                align-items: center;
                padding: 0;
                background: none;
                border: none;
            }
            #date-range {
                min-width: 200px;
                max-width: 250px;
            }
            .gravityflow-export-btn {
                background: #218838;
                color: #fff;
                border: none;
                border-radius: 5px;
                padding: 10px 22px;
                font-size: 16px;
                font-weight: 500;
                cursor: pointer;
                transition: background 0.2s;
            }
            .gravityflow-export-btn:hover {
                background: #18692c;
            }
            @media (max-width: 900px) {
                .gravityflow-report-filters-flex {
                    flex-direction: column;
                    align-items: stretch;
                }
                .filter-group-left {
                    flex-wrap: wrap;
                }
                .filter-group-right {
                    justify-content: flex-end;
                    margin-top: 10px;
                }
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
     * @param string $start_date Fecha de inicio del filtro personalizado
     * @param string $end_date Fecha de fin del filtro personalizado
     * @param string $table_alias Alias de la tabla de actividad
     * @return string Condición SQL para filtrar por fecha
     */
    private function build_date_condition($period, $start_date = null, $end_date = null, $table_alias = 'al') {
        if ($period === 'all') {
            return '';
        }
        if ($period === 'today') {
            return " AND DATE({$table_alias}.date_created) = CURDATE()";
        }
        if ($period === 'last_week') {
            return " AND YEARWEEK({$table_alias}.date_created, 1) = YEARWEEK(CURDATE() - INTERVAL 1 WEEK, 1)";
        }
        if (is_numeric($period)) {
            $date_limit = date('Y-m-d H:i:s', strtotime("-{$period} months"));
            return " AND {$table_alias}.date_created >= '{$date_limit}'";
        }
        // Filtro personalizado
        if ($period === 'custom' && $start_date && $end_date) {
            $start = $start_date . ' 00:00:00';
            $end = $end_date . ' 23:59:59';
            return " AND {$table_alias}.date_created >= '{$start}' AND {$table_alias}.date_created <= '{$end}'";
        }
        return '';
    }

    /**
     * Obtiene estadísticas de workflow por usuario asignado
     * @param string $period Período para filtrar
     * @param int $form_id ID del formulario
     * @param string $start_date Fecha de inicio del filtro personalizado
     * @param string $end_date Fecha de fin del filtro personalizado
     * @return array Estadísticas de tareas completadas y aprobadas por usuario
     */
    private function get_workflow_stats($period, $form_id, $start_date = null, $end_date = null) {
        global $wpdb;
        $prefix = $this->get_table_prefix();
        
        $date_condition = $this->build_date_condition($period, $start_date, $end_date);
        
        $query = $wpdb->prepare("
            SELECT 
                al.assignee_id,
                al.assignee_type,
                CASE 
                    WHEN al.assignee_type = 'user_id' THEN u.display_name
                    WHEN al.assignee_type = 'role' THEN CONCAT('[ROL] ', UPPER(al.assignee_id))
                END as display_name,
                SUM(CASE WHEN al.log_value = 'complete' THEN 1 ELSE 0 END) as total_completed,
                SUM(CASE WHEN al.log_value IN ('approved', 'rejected') THEN 1 ELSE 0 END) as total_approved,
                ROUND(AVG(al.duration / 3600), 2) as avg_duration
            FROM {$prefix}gravityflow_activity_log al
            LEFT JOIN {$wpdb->users} u ON al.assignee_id = u.ID AND al.assignee_type = 'user_id'
            INNER JOIN {$prefix}gf_entry e ON al.lead_id = e.id
            WHERE 
                al.log_value IN ('complete', 'approved', 'rejected')
                AND al.assignee_type IN ('user_id', 'role')
                AND e.status = 'active'
                AND al.form_id = %d
                {$date_condition}
            GROUP BY 
                al.assignee_id,
                al.assignee_type,
                display_name
            ORDER BY 
                display_name
        ", $form_id);
        
        $results = $wpdb->get_results($query);
        
        if ($wpdb->last_error) {
            return array();
        }
        
        return $results;
    }

    /**
     * Obtiene estadísticas por paso del workflow
     * @param int $form_id ID del formulario
     * @param string $period Período para filtrar
     * @param string $start_date Fecha de inicio del filtro personalizado
     * @param string $end_date Fecha de fin del filtro personalizado
     * @return array Estadísticas de tareas completadas y duración promedio por paso
     */
    private function get_step_stats($form_id, $period, $start_date = null, $end_date = null) {
        global $wpdb;
        
        try {
            // Verificar que la clase existe
            if (!class_exists('Gravity_Flow_API')) {
                throw new Exception('GravityFlow no está disponible');
            }
            
            // Obtener los pasos directamente
            $api = new Gravity_Flow_API($form_id);
            $steps = $api->get_steps();
            
            if (empty($steps)) {
                throw new Exception('No hay pasos configurados en este workflow');
            }
            
            // Construir la condición de fecha
            $date_condition = $this->build_date_condition($period, $start_date, $end_date);
            
            // Consulta para obtener estadísticas por paso
            $query = $wpdb->prepare("
                SELECT 
                    al.feed_id,
                    COUNT(*) as total_completed,
                    ROUND(AVG(al.duration / 3600), 2) as avg_duration
                FROM {$wpdb->prefix}gravityflow_activity_log al
                INNER JOIN {$wpdb->prefix}gf_entry e ON al.lead_id = e.id
                WHERE al.log_object = 'step'
                AND al.log_event = 'ended'
                AND al.log_value IN ('complete', 'approved', 'rejected')
                AND e.status = 'active'
                AND al.form_id = %d
                {$date_condition}
                GROUP BY al.feed_id
            ", $form_id);
            
            $results = $wpdb->get_results($query);
            
            if ($wpdb->last_error) {
                throw new Exception('Error al consultar la base de datos: ' . $wpdb->last_error);
            }
            
            // Procesar los resultados
            $stats = [];
            foreach ($steps as $step) {
                try {
                    $step_id = $step->get_id();
                    $step_name = $step->get_name();
                    $step_type = $step->get_type();
                    
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
                    continue;
                }
            }
            
            return $stats;
            
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Obtiene estadísticas mensuales del workflow
     * @param int $form_id ID del formulario
     * @param string $period Período para filtrar
     * @param string $start_date Fecha de inicio del filtro personalizado
     * @param string $end_date Fecha de fin del filtro personalizado
     * @return array Estadísticas de tareas completadas y duración promedio por mes
     */
    private function get_monthly_stats($form_id, $period, $start_date = null, $end_date = null) {
        global $wpdb;
        
        try {
            // Construir la condición de fecha
            $date_condition = $this->build_date_condition($period, $start_date, $end_date);
            
            // Consulta para obtener estadísticas mensuales
            $query = $wpdb->prepare("
                SELECT 
                    DATE_FORMAT(al.date_created, '%Y-%m') as month,
                    DATE_FORMAT(al.date_created, '%M %Y') as display_name,
                    COUNT(*) as total_completed,
                    ROUND(AVG(al.duration/3600), 2) as avg_duration
                FROM {$wpdb->prefix}gravityflow_activity_log al
                INNER JOIN {$wpdb->prefix}gf_entry e ON al.lead_id = e.id
                WHERE al.log_object = 'workflow'
                AND al.log_event = 'ended'
                AND al.log_value IN ('complete', 'approved', 'rejected')
                AND e.status = 'active'
                AND al.form_id = %d
                {$date_condition}
                GROUP BY DATE_FORMAT(al.date_created, '%Y-%m')
                ORDER BY month DESC
            ", $form_id);
            
            $results = $wpdb->get_results($query);
            
            if ($wpdb->last_error) {
                throw new Exception('Error al consultar la base de datos: ' . $wpdb->last_error);
            }
            
            // Procesar los resultados para traducir los nombres de los meses
            $meses = array(
                'January' => 'Enero',
                'February' => 'Febrero',
                'March' => 'Marzo',
                'April' => 'Abril',
                'May' => 'Mayo',
                'June' => 'Junio',
                'July' => 'Julio',
                'August' => 'Agosto',
                'September' => 'Septiembre',
                'October' => 'Octubre',
                'November' => 'Noviembre',
                'December' => 'Diciembre'
            );
            
            $stats = array();
            foreach ($results as $result) {
                // Extraer el mes y año del display_name
                list($month, $year) = explode(' ', $result->display_name);
                // Traducir el mes
                $month_es = $meses[$month] ?? $month;
                
                $stats[] = array(
                    'month' => $result->month,
                    'display_name' => $month_es . ' ' . $year,
                    'total_completed' => $result->total_completed,
                    'avg_duration' => round($result->avg_duration, 1)
                );
            }
            
            return $stats;
            
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Obtiene estadísticas de tareas pendientes por paso
     * @param int $form_id ID del formulario
     * @param string $period Período para filtrar
     * @param string $start_date Fecha de inicio del filtro personalizado
     * @param string $end_date Fecha de fin del filtro personalizado
     * @return array Estadísticas de tareas pendientes por paso
     */
    private function get_pending_tasks_by_step($form_id, $period, $start_date = null, $end_date = null) {
        global $wpdb;
        $prefix = $this->get_table_prefix();
        
        try {
            // Usar el alias 'a' para la tabla de actividad
            $date_condition = $this->build_date_condition($period, $start_date, $end_date, 'a');

            // Obtener los pasos del workflow
            $api = new Gravity_Flow_API($form_id);
            $steps = $api->get_steps();
            
            $query = $wpdb->prepare("
                SELECT 
                    a.feed_id AS step_id,
                    COUNT(DISTINCT a.lead_id) AS total_tareas_pendientes
                FROM {$prefix}gravityflow_activity_log a
                INNER JOIN {$prefix}gf_entry e ON a.lead_id = e.id
                WHERE a.form_id = %d 
                AND a.log_object = 'assignee' 
                AND a.log_event = 'status' 
                AND a.log_value = 'pending'
                AND e.status = 'active'
                AND NOT EXISTS (
                    SELECT 1 
                    FROM {$prefix}gravityflow_activity_log step
                    WHERE step.lead_id = a.lead_id
                    AND (
                        (step.log_object = 'step' 
                         AND step.log_event = 'ended' 
                         AND step.log_value = 'approved'
                         AND step.date_created > a.date_created)
                        OR
                        (step.log_object = 'assignee'
                         AND step.log_event = 'status'
                         AND step.log_value IN ('complete', 'approved')
                         AND step.date_created > a.date_created)
                        OR
                        (step.log_object = 'workflow' 
                         AND (step.log_event = 'ended' OR step.log_event = 'sent_to_step')
                         AND step.date_created > a.date_created)
                    )
                )
                {$date_condition}
                GROUP BY a.feed_id
                ORDER BY a.feed_id
            ", $form_id);
            
            $results = $wpdb->get_results($query);
            
            if ($wpdb->last_error) {
                throw new Exception('Error al consultar la base de datos: ' . $wpdb->last_error);
            }
            
            // Procesar los resultados y agregar información del paso
            $stats = array();
            foreach ($steps as $step) {
                $step_id = $step->get_id();
                $step_stats = null;
                
                // Buscar las estadísticas para este paso
                foreach ($results as $result) {
                    if ($result->step_id == $step_id) {
                        $step_stats = $result;
                        break;
                    }
                }
                
                $stats[] = array(
                    'step_id' => $step_id,
                    'display_name' => $step->get_name(),
                    'step_type' => $step->get_type(),
                    'total_tareas_pendientes' => $step_stats ? $step_stats->total_tareas_pendientes : 0
                );
            }
            
            return $stats;
            
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Maneja las peticiones AJAX para obtener estadísticas
     * Procesa los parámetros, obtiene las estadísticas y devuelve JSON
     * @return void Envía respuesta JSON con las estadísticas
     */
    public function handle_workflow_stats() {
        // Verificar nonce
        check_ajax_referer('workflow_stats_nonce', 'nonce');
        
        $form_id = isset($_POST['form_id']) ? intval($_POST['form_id']) : 0;
        $period = isset($_POST['period']) ? sanitize_text_field($_POST['period']) : 'all';
        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';
        $period_map = [
            'last_month' => 1,
            'last_3_months' => 3,
            'last_6_months' => 6,
            'last_year' => 12
        ];
        if (isset($period_map[$period])) {
            $period = $period_map[$period];
        }
        $start_date = null;
        $end_date = null;
        if ($period === 'custom') {
            $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : null;
            $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : null;
        }
        
        if (!$form_id) {
            wp_send_json_error('Formulario no válido');
            return;
        }
        
        try {
            if ($type === 'paso') {
                $stats = $this->get_step_stats($form_id, $period, $start_date, $end_date);
            } elseif ($type === 'mensual') {
                $stats = $this->get_monthly_stats($form_id, $period, $start_date, $end_date);
            } elseif ($type === 'tareas_pendientes_paso') {
                $stats = $this->get_pending_tasks_by_step($form_id, $period, $start_date, $end_date);
            } else {
                $stats = $this->get_workflow_stats($period, $form_id, $start_date, $end_date);
            }
            
            wp_send_json_success($stats);
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
} 