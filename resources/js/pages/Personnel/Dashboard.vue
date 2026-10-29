<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowUpRight, CalendarDays, ClipboardCheck, FileUser, ShieldCheck } from '@lucide/vue';
import PersonnelShell from '@/components/personnel/PersonnelShell.vue';

type Module = { menu_id: number; table: string; title: string };
const props = defineProps<{
    modules: Record<string, Module>;
    person: Record<string, unknown> | null;
    assessmentCount: number | null;
    leaveCount: number | null;
}>();
</script>

<template>
    <Head title="工作台" />
    <PersonnelShell :modules="modules" title="工作台" eyebrow="PERSONNEL DESK / 概览">
        <section class="personnel-hero">
            <div class="personnel-hero-copy">
                <span class="personnel-kicker">人事信息服务</span>
                <h2>把重要的人事事项，<br><em>清楚地放在眼前。</em></h2>
                <p>从个人档案到考核与请假记录，按您的权限查看现有系统中的数据。</p>
                <Link v-if="modules.profile" href="/personnel/profile" class="personnel-primary-link">查看个人档案 <ArrowUpRight :size="17" /></Link>
            </div>
            <div class="personnel-hero-art" aria-hidden="true"><div class="personnel-art-ring ring-one"></div><div class="personnel-art-ring ring-two"></div><span>HR</span></div>
        </section>

        <div class="personnel-section-heading"><div><span>01 / QUICK ACCESS</span><h3>常用事项</h3></div><p>根据当前账号权限显示</p></div>
        <section class="personnel-card-grid">
            <Link v-if="modules.profile" href="/personnel/profile" class="personnel-card">
                <FileUser :size="26" :stroke-width="1.5" /><span class="personnel-card-index">01</span><h4>个人档案</h4><p>{{ person?.h5003 || '查看基础信息与任职资料' }}</p><ArrowUpRight class="personnel-card-arrow" :size="19" />
            </Link>
            <Link v-if="modules.assessments" href="/personnel/assessments" class="personnel-card">
                <ClipboardCheck :size="26" :stroke-width="1.5" /><span class="personnel-card-index">02</span><h4>年度考核</h4><p>共 {{ assessmentCount ?? 0 }} 条个人记录</p><ArrowUpRight class="personnel-card-arrow" :size="19" />
            </Link>
            <Link v-if="modules.leave" href="/personnel/leave" class="personnel-card">
                <CalendarDays :size="26" :stroke-width="1.5" /><span class="personnel-card-index">03</span><h4>请假记录</h4><p>共 {{ leaveCount ?? 0 }} 条有效申请</p><ArrowUpRight class="personnel-card-arrow" :size="19" />
            </Link>
        </section>

        <div class="personnel-note"><ShieldCheck :size="21" /><div><strong>数据核对阶段</strong><p>当前页面只读取原人事库。审批和资料修改将在流程验收通过后开放。</p></div></div>
    </PersonnelShell>
</template>
