<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, CalendarDays, Plus } from '@lucide/vue';
import PersonnelShell from '@/components/personnel/PersonnelShell.vue';
import '@/../css/leave.css';

type Module = { menu_id: number; table: string; title: string };
type RecordRow = { id: number; kind: string; name: string; summary: string; date: string; status: string; pending?: boolean };
const props = defineProps<{ modules: Record<string, Module>; title: string; mode: string; records: RecordRow[] | { data: RecordRow[]; links: { url: string | null; label: string; active: boolean }[] } }>();
const rows = computed(() => Array.isArray(props.records) ? props.records : props.records.data);
const links = computed(() => Array.isArray(props.records) ? [] : props.records.links);
</script>

<template>
    <Head :title="title" />
    <PersonnelShell :modules="modules" :title="title" eyebrow="LEAVE / 请假管理" :active-key="mode === 'review' ? 'leave_review' : mode === 'details' ? 'leave_details' : mode === 'cancellations' ? 'leave_cancel' : 'leave'">
        <div class="leave-header">
            <div class="leave-lede"><CalendarDays :size="23" /><div><strong>{{ title }}</strong><p>{{ mode === 'details' ? '按请假人查看明细，包含同事代填的申请。' : mode === 'review' ? '处理分配给您的请假和销假审批。' : '查看历史记录和办理进度。' }}</p></div></div>
            <Link v-if="mode === 'applications' && modules.leave" href="/personnel/leave/new" class="leave-primary"><Plus :size="16" /> 新建请假申请</Link>
            <Link v-if="mode === 'cancellations' && modules.leave_cancel" href="/personnel/leave/cancellations/new" class="leave-primary"><Plus :size="16" /> 新建销假申请</Link>
        </div>
        <nav class="leave-tabs" aria-label="请假管理">
            <Link v-if="modules.leave || modules.leave_progress" href="/personnel/leave" :class="{ active: mode === 'applications' }">我的申请与进度</Link>
            <Link v-if="modules.leave_details" href="/personnel/leave/details" :class="{ active: mode === 'details' }">我的请假明细</Link>
            <Link v-if="modules.leave_cancel" href="/personnel/leave/cancellations" :class="{ active: mode === 'cancellations' }">销假申请</Link>
            <Link v-if="modules.leave_review" href="/personnel/leave/review" :class="{ active: mode === 'review' }">审批待办与已办</Link>
        </nav>
        <section class="leave-card">
            <div class="leave-card-heading"><span>记录</span><strong>{{ rows.length }} 条</strong></div>
            <div v-if="!rows.length" class="leave-empty">暂无记录</div>
            <Link v-for="record in rows" :key="`${record.kind}-${record.id}`" :href="`/personnel/leave/${record.kind}/${record.id}`" class="leave-record">
                <span class="leave-record-date">{{ record.date || '—' }}</span>
                <span class="leave-record-main"><strong>{{ record.name || '请假申请' }}</strong><small>{{ record.summary || '暂无摘要' }}</small></span>
                <span :class="['leave-badge', { pending: record.pending }]">{{ record.status }}</span><ArrowRight :size="17" />
            </Link>
        </section>
        <div v-if="links.length" class="leave-pagination"><Link v-for="(link, index) in links" :key="index" :href="link.url || '#'" :class="{ active: link.active, disabled: !link.url }" v-html="link.label" /></div>
    </PersonnelShell>
</template>
