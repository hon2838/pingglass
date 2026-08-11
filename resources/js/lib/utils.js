import { clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs) {
    return twMerge(clsx(inputs));
}

export function statusColor(status) {
    return {
        online: 'text-status-online',
        degraded: 'text-status-degraded',
        down: 'text-status-down',
        unknown: 'text-status-unknown',
    }[status] || 'text-status-unknown';
}

export function statusBg(status) {
    return {
        online: 'bg-status-online',
        degraded: 'bg-status-degraded',
        down: 'bg-status-down',
        unknown: 'bg-status-unknown',
    }[status] || 'bg-status-unknown';
}

export function statusLabel(status) {
    return {
        online: 'Online',
        degraded: 'Degraded',
        down: 'Down',
        unknown: 'Unknown',
        operational: 'Operational',
    }[status] || 'Unknown';
}

export function formatLatency(ms) {
    if (ms === null || ms === undefined) return '-';
    return `${ms.toFixed(1)} ms`;
}

export function formatLoss(percent) {
    if (percent === null || percent === undefined) return '-';
    return `${percent.toFixed(1)}%`;
}

export function formatDuration(seconds) {
    if (!seconds) return '-';
    if (seconds < 60) return `${seconds}s`;
    if (seconds < 3600) return `${Math.round(seconds / 60)}m`;
    return `${(seconds / 3600).toFixed(1)}h`;
}

export function displayTimeZone() {
    if (typeof document === 'undefined') return 'Asia/Kuala_Lumpur';

    return document
        .querySelector('meta[name="pingglass-display-timezone"]')
        ?.getAttribute('content') || 'Asia/Kuala_Lumpur';
}

function validDate(value) {
    if (value === null || value === undefined || value === '') return null;

    const date = value instanceof Date ? value : new Date(value);
    return Number.isNaN(date.getTime()) ? null : date;
}

export function formatDateTime(value) {
    const date = validDate(value);
    if (!date) return '-';

    return new Intl.DateTimeFormat(undefined, {
        timeZone: displayTimeZone(),
        year: 'numeric',
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hourCycle: 'h23',
        timeZoneName: 'short',
    }).format(date);
}

export function formatTime(value) {
    const date = validDate(value);
    if (!date) return '-';

    return new Intl.DateTimeFormat(undefined, {
        timeZone: displayTimeZone(),
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hourCycle: 'h23',
        timeZoneName: 'short',
    }).format(date);
}

export function formatChartTime(value) {
    const date = validDate(value);
    if (!date) return '-';

    return new Intl.DateTimeFormat(undefined, {
        timeZone: displayTimeZone(),
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).format(date);
}

export function timeAgo(dateStr) {
    if (!dateStr) return 'Never';
    const date = new Date(dateStr);
    const now = new Date();
    const diff = Math.floor((now - date) / 1000);
    if (diff < 60) return `${diff}s ago`;
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    return `${Math.floor(diff / 86400)}d ago`;
}
