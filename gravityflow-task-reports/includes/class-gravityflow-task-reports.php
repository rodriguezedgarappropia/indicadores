<?php

class GravityFlow_Task_Reports {

    public function get_monthly_stats($form_id, $start_date = null, $end_date = null) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'gravityflow_activity_log';
        
        $where_clause = "WHERE form_id = %d AND log_object = 'workflow' AND log_event = 'ended' AND log_value = 'complete'";
        $params = array($form_id);
        
        if ($start_date && $end_date) {
            $where_clause .= " AND date_created BETWEEN %s AND %s";
            $params[] = $start_date;
            $params[] = $end_date;
        }
        
        $query = $wpdb->prepare(
            "SELECT 
                DATE_FORMAT(date_created, '%Y-%m') as month,
                DATE_FORMAT(date_created, '%M %Y') as display_name,
                COUNT(*) as total_completed,
                AVG(duration) as avg_duration
            FROM $table_name
            $where_clause
            GROUP BY DATE_FORMAT(date_created, '%Y-%m')
            ORDER BY month DESC",
            $params
        );
        
        $results = $wpdb->get_results($query);
        
        if ($wpdb->last_error) {
            error_log('Error en get_monthly_stats: ' . $wpdb->last_error);
            return array();
        }
        
        return $results;
    }
} 