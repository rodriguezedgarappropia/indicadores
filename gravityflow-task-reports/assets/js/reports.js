jQuery(document).ready(function($) {
    // Inicializar Google Charts
    google.charts.load('current', {'packages':['corechart']});
    google.charts.setOnLoadCallback(initializeCharts);
    
    function fetchWorkflowStats() {
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

        let startDate = null;
        let endDate = null;
        if (period === 'custom') {
            const rango = jQuery('#date-range').val();
            if (rango && rango.includes(' a ')) {
                [startDate, endDate] = rango.split(' a ');
            } else if (rango && rango.includes(' to ')) {
                [startDate, endDate] = rango.split(' to ');
            }
            if (!startDate || !endDate) {
                container.html('<div class="notice">Por favor seleccione un rango de fechas válido.</div>');
                jQuery('#loading-spinner').hide();
                return;
            }
        }

        const data = {
            action: 'get_workflow_stats',
            period: period,
            form_id: formId,
            type: type,
            nonce: gfTaskReports.nonce
        };
        if (period === 'custom') {
            data.start_date = startDate;
            data.end_date = endDate;
        }

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
                jQuery('#loading-spinner').hide();
            },
            error: function(xhr, status, error) {
                console.error('Error en la solicitud AJAX:', error);
                container.html('<div class="error">Error al obtener los datos: ' + error + '</div>');
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
        currentStats = stats;  // Guardar los datos actuales
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
        const isMonthlyType = stats[0].hasOwnProperty('month');

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

            // Crear leyenda de colores para los tipos de paso
            const legendContainer = jQuery('<div>', {
                class: 'bar-legend',
                css: {
                    display: 'flex',
                    justifyContent: 'center',
                    gap: '20px',
                    marginTop: '10px',
                    fontSize: '12px'
                }
            });

            // Generar la leyenda según los tipos usados en los datos
            const tiposUsados = new Set(stats.map(step => step.step_type));
            tiposUsados.forEach(tipo => {
                const color = stepColors[tipo] || '#4285F4';
                const label = stepTypeLabels[tipo] || tipo;
                legendContainer.append(`
                    <div style="display: flex; align-items: center;">
                        <span style="display: inline-block; width: 16px; height: 16px; background: ${color}; border-radius: 50%; margin-right: 6px;"></span>
                        <span>${label}</span>
                    </div>
                `);
            });

            barContainer.append(legendContainer);

        } else if (isMonthlyType) {
            // Crear contenedor para gráfico de barras mensual
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

            // Preparar datos para el gráfico de barras mensual
            const chartData = [['Mes', 'Tareas Completadas', { role: 'style' }, { role: 'annotation' }]];
            
            stats.forEach(month => {
                const totalCompleted = parseInt(month.total_completed) || 0;
                const avgDuration = parseFloat(month.avg_duration) || 0;
                chartData.push([
                    month.display_name,
                    totalCompleted,
                    '#4285F4',
                    `${totalCompleted} (${avgDuration.toFixed(1)}h)`
                ]);
            });

            const data = google.visualization.arrayToDataTable(chartData);
            const options = {
                title: 'Estadísticas Mensuales',
                titleTextStyle: {
                    fontSize: 16,
                    bold: true
                },
                height: Math.max(400, stats.length * 50),
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
        'workflow_start': '#7EC8E3',
        'approval': '#4285F4',
        'user_input': '#34A853',
        'notification': '#FBBC05',
        'update_field_values': '#FF7043'
    };

    const stepTypeLabels = {
        'workflow_start': 'Inicio de flujo',
        'user_input': 'Aportación de usuario',
        'approval': 'Aprobación',
        'notification': 'Notificación',
        'workflow_end': 'Fin de flujo',
        'update_field_values': 'Actualizar campos'
    };

    // Función para exportar a Excel
    function exportToExcel(stats, type) {
        console.log('Iniciando exportación a Excel...');
        console.log('XLSX object:', XLSX);
        console.log('XLSX utils:', XLSX?.utils);
        console.log('Tipo de datos a exportar:', type);
        console.log('Datos a exportar:', stats);

        // Verificar disponibilidad de XLSX
        if (typeof XLSX === 'undefined') {
            console.error('Error: La librería XLSX no está definida');
            alert('Error: No se pudo cargar la librería de exportación');
            return;
        }

        try {
            // Preparar los datos según el tipo
            let data = [];
            if (type === 'paso') {
                // Encabezados
                data.push(['Paso', 'Tipo', 'Tareas Completadas', 'Duración Promedio (h)']);
                
                // Datos
                stats.forEach(step => {
                    data.push([
                        step.display_name,
                        stepTypeLabels[step.step_type] || step.step_type,
                        parseInt(step.total_completed) || 0,
                        parseFloat(step.avg_duration) || 0
                    ]);
                });
            } else if (type === 'mensual') {
                // Encabezados para reporte mensual
                data.push(['Mes', 'Tareas Completadas', 'Duración Promedio (h)']);
                
                // Datos
                stats.forEach(month => {
                    data.push([
                        month.display_name,
                        parseInt(month.total_completed) || 0,
                        parseFloat(month.avg_duration) || 0
                    ]);
                });
            } else {
                // Para tipo encargado
                data.push(['Encargado', 'Tareas Completadas', 'Tareas Aprobadas', 'Duración Promedio (h)']);
                stats.forEach(user => {
                    data.push([
                        user.display_name,
                        parseInt(user.total_completed) || 0,
                        parseInt(user.total_approved) || 0,
                        parseFloat(user.avg_duration) || 0
                    ]);
                });
            }

            console.log('Datos preparados:', data);

            // Crear una hoja de cálculo manualmente
            const ws = {};
            const range = {s: {c:0, r:0}, e: {c:data[0].length-1, r:data.length-1}};
            
            // Convertir el array 2D a formato de celda de XLSX
            for(let R = 0; R < data.length; ++R) {
                for(let C = 0; C < data[R].length; ++C) {
                    const cell_ref = XLSX.utils.encode_cell({c:C, r:R});
                    ws[cell_ref] = {
                        v: data[R][C], // valor
                        t: typeof data[R][C] === 'number' ? 'n' : 's' // tipo (número o string)
                    };

                    // Aplicar estilos a los encabezados (primera fila)
                    if (R === 0) {
                        ws[cell_ref].s = {
                            font: {
                                bold: true,
                                color: { rgb: "FFFFFF" }
                            },
                            fill: {
                                fgColor: { rgb: "4285F4" }
                            },
                            alignment: {
                                horizontal: "center",
                                vertical: "center"
                            }
                        };
                    }
                }
            }
            
            // Establecer el rango usado y propiedades de columna
            ws['!ref'] = XLSX.utils.encode_range(range);
            
            // Establecer ancho de columnas
            ws['!cols'] = [];
            for(let i = 0; i < data[0].length; i++) {
                ws['!cols'].push({ wch: 20 }); // wch es el ancho en caracteres
            }
            
            // Establecer alto de filas
            ws['!rows'] = [{ hpt: 25 }]; // hpt es el alto en puntos para la primera fila

            // Crear el workbook
            const wb = {
                SheetNames: ['Reporte'],
                Sheets: { 'Reporte': ws }
            };

            console.log('Workbook creado:', wb);

            // Generar el archivo
            const wbout = XLSX.write(wb, {bookType:'xlsx', type:'binary'});

            // Función para convertir string a ArrayBuffer
            function s2ab(s) {
                const buf = new ArrayBuffer(s.length);
                const view = new Uint8Array(buf);
                for (let i=0; i<s.length; i++) view[i] = s.charCodeAt(i) & 0xFF;
                return buf;
            }

            // Crear el Blob y descargar
            const fileName = `reporte_${type}_${new Date().toISOString().split('T')[0]}.xlsx`;
            const blob = new Blob([s2ab(wbout)], {type:'application/octet-stream'});
            
            // Crear link de descarga
            const elem = window.document.createElement('a');
            elem.href = window.URL.createObjectURL(blob);
            elem.download = fileName;
            document.body.appendChild(elem);
            elem.click();
            document.body.removeChild(elem);
            
            console.log('Archivo descargado exitosamente');

        } catch (error) {
            console.error('Error durante la exportación:', error);
            alert('Ocurrió un error durante la exportación: ' + error.message);
        }
    }

    // Evento para el botón de exportar
    jQuery('#export-excel').on('click', function() {
        console.log('Botón de exportar clickeado');
        const type = jQuery('#type-filter').val();
        console.log('Tipo seleccionado:', type);
        
        if (!currentStats || currentStats.length === 0) {
            console.warn('No hay datos para exportar');
            alert('No hay datos para exportar. Por favor, seleccione los filtros y espere a que se carguen los datos.');
            return;
        }
        
        console.log('Iniciando exportación con datos:', currentStats);
        exportToExcel(currentStats, type);
    });

    // Variable para almacenar los datos actuales
    let currentStats = null;

    // Actualizar al cambiar los filtros
    $('.gravityflow-filter').change(function() {
        fetchWorkflowStats();
    });

    // Mostrar/ocultar el selector de fechas personalizado según el período seleccionado
    function toggleCustomDateContainer() {
        const period = jQuery('#period-filter').val();
        if (period === 'custom') {
            jQuery('#custom-date-container').show();
        } else {
            jQuery('#custom-date-container').hide();
        }
    }

    // Evento para el cambio de período
    jQuery(document).on('change', '#period-filter', function() {
        toggleCustomDateContainer();
    });

    // Inicializar flatpickr si está disponible
    if (typeof flatpickr !== 'undefined') {
        flatpickr('#date-range', {
            mode: 'range',
            dateFormat: 'Y-m-d',
            locale: 'es',
            maxDate: 'today',
            allowInput: true,
            onClose: function(selectedDates, dateStr, instance) {
                if (selectedDates.length === 2 && jQuery('#period-filter').val() === 'custom') {
                    fetchWorkflowStats();
                }
            }
        });
    }

    // Al cargar la página, asegurarse de que el contenedor esté oculto si no es personalizado
    toggleCustomDateContainer();
}); 