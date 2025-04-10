jQuery(document).ready(function($) {
    // Inicializar Google Charts
    google.charts.load('current', {'packages':['corechart']});
    google.charts.setOnLoadCallback(initializeCharts);
    
    function fetchWorkflowStats() {
        // Mostrar el spinner al inicio de la petición
        jQuery('#loading-spinner').show();
        
        const period = jQuery('#period-filter').val();
        const formId = jQuery('#form-filter').val();
        const type = jQuery('#type-filter').val();
        const container = jQuery('#charts_container');

        if (!formId) {
            container.html('<div class="notice">Por favor seleccione un formulario</div>');
            jQuery('#loading-spinner').hide();
            return;
        }

        if (!type) {
            container.html('<div class="notice">Por favor seleccione un tipo</div>');
            jQuery('#loading-spinner').hide();
            return;
        }

        const data = {
            action: 'get_workflow_stats',
            period: period,
            form_id: formId,
            type: type,
            nonce: gfTaskReports.nonce
        };

        console.log('Enviando datos:', data);

        jQuery.ajax({
            url: gfTaskReports.ajaxurl,
            type: 'POST',
            data: data,
            success: function(response) {
                console.log('Respuesta completa:', response);
                
                if (response.success) {
                    updateCharts(response.data);
                } else {
                    console.error('Error en la respuesta:', response.data);
                    container.html('<div class="error">Error al obtener los datos.</div>');
                }
                // Ocultar el spinner después de procesar la respuesta
                jQuery('#loading-spinner').hide();
            },
            error: function(xhr, status, error) {
                console.error('Error en la solicitud AJAX:', error);
                container.html('<div class="error">Error al obtener los datos: ' + error + '</div>');
                // Ocultar el spinner en caso de error
                jQuery('#loading-spinner').hide();
            }
        });
    }

    function initializeCharts() {
        const container = jQuery('#charts_container');
        container.html('<div class="notice">Por favor, seleccione un formulario y tipo para ver el reporte.</div>');

        // Evento para el botón de filtrar
        jQuery('#apply-filter').on('click', function() {
            fetchWorkflowStats();
        });
    }

    function updateCharts(stats) {
        const container = jQuery('#charts_container');
        container.empty();

        if (!stats || stats.length === 0) {
            container.html('<div class="notice">No hay datos disponibles para mostrar.</div>');
            return;
        }

        // Crear contenedor de la cuadrícula
        const gridContainer = jQuery('<div>', {
            class: 'charts-grid',
            css: {
                display: 'flex',
                flexWrap: 'wrap',
                justifyContent: 'center',
                gap: '20px',
                width: '100%'
            }
        });

        container.append(gridContainer);

        stats.forEach((userData, index) => {
            // Crear contenedor para cada gráfico
            const chartDiv = jQuery('<div>', {
                id: 'chart_' + index,
                class: 'chart-container',
                css: {
                    width: 'calc(50% - 20px)',
                    minWidth: '400px',
                    height: '450px',
                    marginBottom: '20px',
                    position: 'relative',
                    backgroundColor: '#fff',
                    borderRadius: '8px',
                    boxShadow: '0 2px 4px rgba(0,0,0,0.1)',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    flexShrink: 0,
                    overflow: 'hidden'
                }
            });

            gridContainer.append(chartDiv);

            // Crear contenedor interno para el gráfico
            const chartInnerDiv = jQuery('<div>', {
                id: 'chart_inner_' + index,
                css: {
                    width: '100%',
                    height: '100%',
                    position: 'relative',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    paddingBottom: '30px'
                }
            });

            chartDiv.append(chartInnerDiv);

            const data = google.visualization.arrayToDataTable([
                ['Estado', 'Cantidad'],
                ['Completadas', parseInt(userData.total_completed) || 0],
                ['Aprobadas', parseInt(userData.total_approved) || 0]
            ]);

            const options = {
                pieHole: 0.6,
                chartArea: {
                    left: '5%',
                    top: '10%',
                    width: '90%',
                    height: '75%'
                },
                colors: ['#4285F4', '#34A853'],
                legend: {
                    position: 'bottom',
                    alignment: 'center',
                    textStyle: {
                        fontSize: 12
                    }
                },
                pieSliceText: 'percentage',
                backgroundColor: 'transparent',
                sliceVisibilityThreshold: 0
            };

            const chart = new google.visualization.PieChart(document.getElementById('chart_inner_' + index));
            
            chart.draw(data, options);

            const centerDiv = jQuery('<div>', {
                class: 'chart-center-text',
                css: {
                    position: 'absolute',
                    top: '45%',
                    left: '50%',
                    transform: 'translate(-50%, -50%)',
                    textAlign: 'center',
                    pointerEvents: 'none',
                    width: '140px',
                    zIndex: 1000
                }
            });

            // Formatear el tiempo promedio
            const avgTime = parseFloat(userData.avg_duration) || 0;
            const formattedTime = avgTime.toFixed(1);

            // Contenido del texto central sin fondo
            centerDiv.html(`
                <div style="padding: 10px;">
                    <div style="font-size: 15px; color: #4285F4; margin-bottom: 2px; font-weight: 500;">${userData.display_name}</div>
                    <div style="font-size: 26px; font-weight: 600; line-height: 1.2; color: #202124;">
                        ${parseInt(userData.total_completed) + parseInt(userData.total_approved)}
                    </div>
                    <div style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #5F6368; margin-bottom: 4px;">
                        TAREAS TOTALES
                    </div>
                    <div style="height: 1px; background: rgba(0,0,0,0.1); margin: 4px 0;"></div>
                    <div style="font-size: 20px; font-weight: 600; line-height: 1.2; color: #202124;">
                        ${formattedTime}
                    </div>
                    <div style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #5F6368;">
                        HORAS PROMEDIO
                    </div>
                </div>
            `);

            // Agregar el div central al contenedor interno
            chartInnerDiv.append(centerDiv);
        });
    }

    // Actualizar al cambiar los filtros
    $('.gravityflow-filter').change(function() {
        fetchWorkflowStats();
    });
}); 