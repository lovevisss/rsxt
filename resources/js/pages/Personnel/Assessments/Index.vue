<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, ClipboardCheck, FileText } from '@lucide/vue';
import PersonnelShell from '@/components/personnel/PersonnelShell.vue';
import '@/../css/assessments.css';

type Module = { menu_id: number; table: string; title: string };
defineProps<{ modules: Record<string, Module>; records: { id: number; year: string; status: string; departmentResult: string; schoolResult: string }[] }>();
</script>

<template>
    <Head title="年度考核" />
    <PersonnelShell :modules="modules" title="年度考核" eyebrow="ASSESSMENT / 历年记录" active-key="assessments">
        <div class="assessment-intro"><ClipboardCheck :size="22" /><div><strong>我的年度考核</strong><p>选择年度查看个人信息、年度总结和审核进度。年度记录由管理端统一建立。</p></div></div>
        <div class="assessment-list">
            <div v-if="!records.length" class="assessment-empty">暂无年度考核记录</div>
            <Link v-for="record in records" :key="record.id" :href="`/personnel/assessments/${record.id}`" class="assessment-list-row">
                <span class="assessment-list-icon"><FileText :size="20" /></span>
                <span class="assessment-list-main"><strong>{{ record.year }} 年度考核</strong><small>部门结果：{{ record.departmentResult || '待评定' }} · 学校结果：{{ record.schoolResult || '待评定' }}</small></span>
                <span class="assessment-status">{{ record.status }}</span><ArrowRight :size="18" />
            </Link>
        </div>
    </PersonnelShell>
</template>
