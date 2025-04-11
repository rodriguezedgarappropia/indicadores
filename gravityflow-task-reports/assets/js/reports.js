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
        jQuery('#loading-spinner').show();
        const container = jQuery('#charts_container');
        container.empty();

        if (!stats || stats.length === 0) {
            container.html('<div class="notice">No hay datos disponibles para mostrar.</div>');
            jQuery('#loading-spinner').hide();
            return;
        }

        // Determinar el tipo de datos basado en el primer elemento
        const isStepType = stats[0].hasOwnProperty('step_type');

        if (isStepType) {
            // Crear contenedor para gráfico de barras
            const barContainer = jQuery('<div>', {
                class: 'bar-chart-container',
                css: {
                    width: '100%',
                    height: '600px',
                    marginBottom: '20px',
                    backgroundColor: '#fff',
                    borderRadius: '8px',
                    boxShadow: '0 2px 4px rgba(0,0,0,0.1)',
                    padding: '20px'
                }
            });

            container.append(barContainer);

            // Preparar datos para el gráfico de barras
            const chartData = [['Paso', 'Tareas Completadas', { role: 'style' }, { role: 'annotation' }]];
            
            stats.forEach(step => {
                const color = stepColors[step.step_type] || '#4285F4';
                const totalCompleted = parseInt(step.total_completed) || 0;
                const avgDuration = parseFloat(step.avg_duration) || 0;
                chartData.push([
                    step.display_name,
                    totalCompleted,
                    color,
                    `${totalCompleted} (${avgDuration.toFixed(1)}h)`
                ]);
            });

            const data = google.visualization.arrayToDataTable(chartData);
            const options = {
                title: 'Estadísticas por Paso',
                titleTextStyle: {
                    fontSize: 16,
                    bold: true
                },
                height: Math.max(400, stats.length * 50), // Altura dinámica basada en número de pasos
                legend: { position: 'none' },
                chartArea: {
                    left: '20%',
                    right: '15%',
                    top: '10%',
                    bottom: '10%',
                    width: '100%',
                    height: '80%'
                },
                hAxis: {
                    title: 'Tareas Completadas',
                    titleTextStyle: {
                        fontSize: 14,
                        italic: false
                    },
                    minValue: 0
                },
                vAxis: {
                    title: '',
                    textStyle: {
                        fontSize: 12
                    }
                },
                annotations: {
                    textStyle: {
                        fontSize: 12,
                        color: '#555'
                    },
                    alwaysOutside: true
                },
                bar: { groupWidth: '70%' }
            };

            const chart = new google.visualization.BarChart(barContainer[0]);
            chart.draw(data, options);

        } else {
            // Crear contenedor de la cuadrícula para gráficos circulares
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

            stats.forEach((itemData, index) => {
                // Crear contenedor para cada gráfico circular
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

                const chartData = [
                    ['Estado', 'Cantidad'],
                    ['Completadas', parseInt(itemData.total_completed) || 0],
                    ['Aprobadas', parseInt(itemData.total_approved) || 0]
                ];

                const data = google.visualization.arrayToDataTable(chartData);
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

                // Crear el contenido central para gráfico circular
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

                const avgTime = parseFloat(itemData.avg_duration) || 0;
                const formattedTime = avgTime.toFixed(1);

                centerDiv.html(`
                    <div style="padding: 10px;">
                        <div style="font-size: 15px; color: #4285F4; margin-bottom: 2px; font-weight: 500;">
                            ${itemData.display_name}
                        </div>
                        <div style="font-size: 26px; font-weight: 600; line-height: 1.2; color: #202124;">
                            ${parseInt(itemData.total_completed) + parseInt(itemData.total_approved)}
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

                chartInnerDiv.append(centerDiv);
            });
        }

        jQuery('#loading-spinner').hide();
    }

    // Definir colores según el tipo de paso
    const stepColors = {
        'approval': '#4285F4',
        'user_input': '#34A853',
        'notification': '#FBBC05'
    };

    // Actualizar al cambiar los filtros
    $('.gravityflow-filter').change(function() {
        fetchWorkflowStats();
    });
}); 