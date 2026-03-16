import { chartThemeColors } from '../../runtime/theme';

function resolveStateCategories(widget) {
    const mappings = Array.isArray(widget?.state_mappings) ? widget.state_mappings : [];

    return ['Unknown', ...mappings.map((mapping) => {
        if (typeof mapping?.label === 'string' && mapping.label.trim() !== '') {
            return mapping.label.trim();
        }

        return typeof mapping?.value === 'string' ? mapping.value : 'Unknown';
    })];
}

function resolveVisualMapPieces(widget) {
    const mappings = Array.isArray(widget?.state_mappings) ? widget.state_mappings : [];
    const pieces = [{ value: 0, color: '#64748b' }];

    mappings.forEach((mapping, index) => {
        pieces.push({
            value: index + 1,
            color: typeof mapping?.color === 'string' && mapping.color.trim() !== ''
                ? mapping.color.trim()
                : '#64748b',
        });
    });

    return pieces;
}

export function stateTimelineOption(widget, series) {
    const theme = chartThemeColors();
    const categories = resolveStateCategories(widget);
    const yAxisMax = Math.max(0, categories.length - 1);

    return {
        backgroundColor: 'transparent',
        animation: true,
        grid: {
            left: 48,
            right: 18,
            top: 28,
            bottom: 36,
            containLabel: true,
        },
        visualMap: {
            show: false,
            dimension: 1,
            pieces: resolveVisualMapPieces(widget),
        },
        tooltip: {
            trigger: 'axis',
            backgroundColor: theme.tooltipBg,
            borderColor: theme.tooltipBorder,
            textStyle: {
                color: theme.tooltipText,
            },
            formatter: (params) => {
                const point = Array.isArray(params) && params.length > 0 ? params[0] : null;
                const seriesValue = Array.isArray(point?.value) ? point.value[1] : null;
                const index = Number.isFinite(Number(seriesValue)) ? Number(seriesValue) : 0;
                const label = categories[index] ?? 'Unknown';

                return `${point?.axisValueLabel ?? ''}<br/>${label}`;
            },
        },
        xAxis: {
            type: 'time',
            axisLine: { lineStyle: { color: theme.axisLineColor } },
            splitLine: { show: false },
            axisLabel: {
                color: theme.axisLabelColor,
                fontSize: 11,
            },
        },
        yAxis: {
            type: 'value',
            min: 0,
            max: yAxisMax,
            interval: 1,
            axisLine: { show: false },
            splitLine: { lineStyle: { color: theme.splitLineColor } },
            axisLabel: {
                color: theme.axisLabelColor,
                fontSize: 11,
                formatter: (value) => categories[Number(value)] ?? 'Unknown',
            },
        },
        series: (Array.isArray(series) ? series : []).map((entry) => ({
            name: entry.label,
            type: 'line',
            step: 'end',
            showSymbol: true,
            symbol: 'circle',
            symbolSize: 7,
            lineStyle: {
                width: 4,
                color: entry.color,
                opacity: 1,
            },
            itemStyle: {
                color: (params) => {
                    const stateColor = params?.data?.stateColor;

                    return typeof stateColor === 'string' && stateColor.trim() !== ''
                        ? stateColor.trim()
                        : entry.color;
                },
                borderColor: '#ffffff',
                borderWidth: 1.5,
            },
            areaStyle: {
                opacity: 0.12,
                color: entry.color,
            },
            emphasis: { focus: 'series' },
            data: (Array.isArray(entry.points) ? entry.points : []).map((point) => ({
                value: [point.timestamp, point.value],
                rawValue: point.rawValue ?? null,
                stateLabel: point.stateLabel ?? null,
                stateColor: point.stateColor ?? entry.color,
            })),
        })),
    };
}
