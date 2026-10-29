<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, ShieldCheck } from '@lucide/vue';
import PersonnelShell from '@/components/personnel/PersonnelShell.vue';
import '@/../css/assessments.css';

type Module = { menu_id: number; table: string; title: string };
type Row = { id: number; name: string; employeeNumber: string; department: string; year: string };
defineProps<{ modules: Record<string, Module>; records: { data: Row[]; links: { url: string | null; label: string; active: boolean }[] } }>();
</script>

<template>
    <Head title="部门考核审核" />
    <PersonnelShell :modules="modules" title="部门考核审核" eyebrow="ASSESSMENT / DEPARTMENT" active-key="assessment_review">
        <div class="assessment-intro"><ShieldCheck :size="22" /><div><strong>待部门审核</strong><p>仅显示您负责部门内已提交、尚未完成部门审核的记录。</p></div></div>
        <div class="assessment-list">
            <div v-if="!records.data.length" class="assessment-empty">当前没有待审核的年度考核</div>
            <Link v-for="record in records.data" :key="record.id" :href="`/personnel/assessments/${record.id}`" class="assessment-list-row">
                <span class="assessment-list-main"><strong>{{ record.name }} · {{ record.year }} 年度</strong><small>{{ record.employeeNumber }} · {{ record.department }}</small></span>
                <span class="assessment-status">待部门审核</span><ArrowRight :size="18" />
            </Link>
        </div>
        <nav v-if="records.links.length > 3" class="assessment-pages" aria-label="分页">
            <Link v-for="(link, index) in records.links" :key="index" :href="link.url || '#'" :class="{ active: link.active, disabled: !link.url }" v-html="link.label" />
        </nav>
    </PersonnelShell>
</template>
