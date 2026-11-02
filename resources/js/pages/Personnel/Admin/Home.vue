<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowUpRight,
    BriefcaseBusiness,
    Building2,
    Layers3,
    ShieldCheck,
    ScrollText,
} from '@lucide/vue';
import PersonnelShell from '@/components/personnel/PersonnelShell.vue';

type Module = { menu_id: number; table: string; title: string };
const props = defineProps<{ modules: Record<string, Module> }>();
const tools = [
    {
        key: 'position_admin',
        number: '01',
        title: '聘任岗位变动',
        description: '维护东方与本部的岗位变动记录',
        href: '/personnel/admin/positions',
        icon: BriefcaseBusiness,
    },
    {
        key: 'grade_admin',
        number: '02',
        title: '所聘岗位等级',
        description: '管理两地等级及聘任时间',
        href: '/personnel/admin/grades',
        icon: Layers3,
    },
    {
        key: 'department_admin',
        number: '03',
        title: '人员部门变动',
        description: '登记历史变动并同步当前部门',
        href: '/personnel/admin/departments',
        icon: Building2,
    },
    {
        key: 'admin_accounts',
        number: '04',
        title: '后台管理员',
        description: '维护可进入管理后台的人员',
        href: '/personnel/admin/administrators',
        icon: ShieldCheck,
    },
    {
        key: 'admin_operations',
        number: '05',
        title: '操作记录',
        description: '查看管理员动作与回滚状态',
        href: '/personnel/admin/operations',
        icon: ScrollText,
    },
];
</script>

<template>
    <Head title="管理工作台" />
    <PersonnelShell
        workspace="admin"
        :modules="modules"
        title="管理工作台"
        eyebrow="ADMINISTRATION / 概览"
        active-key="admin_home"
    >
        <section class="admin-home-intro">
            <div>
                <span>ADMIN WORKSPACE</span>
                <h2>人事管理，集中处理。</h2>
                <p>选择一项管理业务，查看记录并处理变动。</p>
            </div>
            <div class="admin-home-seal" aria-hidden="true">
                HR<span>ADMIN</span>
            </div>
        </section>
        <div class="personnel-section-heading">
            <div>
                <span>01 / MANAGEMENT</span>
                <h3>管理事项</h3>
            </div>
            <p>仅展示当前账号获准使用的功能</p>
        </div>
        <section class="admin-home-grid">
            <Link
                v-for="tool in tools.filter((item) => props.modules[item.key])"
                :key="tool.key"
                :href="tool.href"
                class="admin-home-card"
            >
                <div class="admin-home-card-top">
                    <span>{{ tool.number }} / 05</span
                    ><ArrowUpRight :size="18" />
                </div>
                <component :is="tool.icon" :size="29" :stroke-width="1.5" />
                <h4>{{ tool.title }}</h4>
                <p>{{ tool.description }}</p>
            </Link>
        </section>
    </PersonnelShell>
</template>
