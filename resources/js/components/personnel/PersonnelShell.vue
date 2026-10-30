<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    ArrowRight,
    BriefcaseBusiness,
    CalendarDays,
    ClipboardCheck,
    FilePenLine,
    History,
    LayoutDashboard,
    LogOut,
    ShieldCheck,
    UserRound,
    UsersRound,
    Layers3,
    Building2,
} from '@lucide/vue';

type Module = { menu_id: number; table: string; title: string };
const props = defineProps<{
    modules: Record<string, Module>;
    title: string;
    eyebrow: string;
    activeKey?: string;
}>();
const page = usePage();
const userName = computed(
    () =>
        (page.props.auth as { user?: { name: string } } | undefined)?.user
            ?.name ?? '',
);
const currentPath = computed(() => page.url.split('?')[0]);
const items = computed(() =>
    [
        {
            key: 'dashboard',
            title: '工作台',
            href: '/dashboard',
            icon: LayoutDashboard,
            visible: true,
        },
        {
            key: 'profile',
            title: '个人档案',
            href: '/personnel/profile',
            icon: UserRound,
            visible: !!props.modules.profile,
        },
        {
            key: 'corrections',
            title: '更正申请',
            href: '/personnel/corrections',
            icon: FilePenLine,
            visible: !!props.modules.corrections,
        },
        {
            key: 'correction_review',
            title: '更正审核',
            href: '/personnel/admin/profile-corrections',
            icon: ShieldCheck,
            visible: !!props.modules.correction_review,
        },
        {
            key: 'history',
            title: '档案履历',
            href: '/personnel/history',
            icon: History,
            visible: !!props.modules.history,
        },
        {
            key: 'assessments',
            title: '年度考核',
            href: '/personnel/assessments',
            icon: ClipboardCheck,
            visible: !!props.modules.assessments,
        },
        {
            key: 'assessment_review',
            title: '部门考核审核',
            href: '/personnel/assessments/review',
            icon: ShieldCheck,
            visible: !!props.modules.assessment_review,
        },
        {
            key: 'leave',
            title: '请假申请与进度',
            href: '/personnel/leave',
            icon: CalendarDays,
            visible: !!props.modules.leave || !!props.modules.leave_progress,
        },
        {
            key: 'leave_details',
            title: '我的请假明细',
            href: '/personnel/leave/details',
            icon: CalendarDays,
            visible: !!props.modules.leave_details,
        },
        {
            key: 'leave_cancel',
            title: '销假申请',
            href: '/personnel/leave/cancellations',
            icon: CalendarDays,
            visible: !!props.modules.leave_cancel,
        },
        {
            key: 'leave_review',
            title: '请假审批',
            href: '/personnel/leave/review',
            icon: ShieldCheck,
            visible: !!props.modules.leave_review,
        },
        {
            key: 'position_admin',
            title: '聘任岗位变动',
            href: '/personnel/admin/positions',
            icon: BriefcaseBusiness,
            visible: !!props.modules.position_admin,
        },
        {
            key: 'grade_admin',
            title: '所聘岗位等级',
            href: '/personnel/admin/grades',
            icon: Layers3,
            visible: !!props.modules.grade_admin,
        },
        {
            key: 'department_admin',
            title: '人员部门变动',
            href: '/personnel/admin/departments',
            icon: Building2,
            visible: !!props.modules.department_admin,
        },
        {
            key: 'admin_accounts',
            title: '后台管理员',
            href: '/personnel/admin/administrators',
            icon: UsersRound,
            visible: !!props.modules.admin_accounts,
        },
    ].filter((item) => item.visible),
);
</script>

<template>
    <div class="personnel-app">
        <aside class="personnel-sidebar">
            <div class="personnel-brand">
                <div class="personnel-brand-mark">
                    <BriefcaseBusiness :size="23" :stroke-width="1.8" />
                </div>
                <div>
                    <span class="personnel-brand-en">PERSONNEL / 01</span
                    ><strong>人事管理平台</strong>
                </div>
            </div>
            <p class="personnel-side-label">工作空间</p>
            <nav class="personnel-nav" aria-label="主导航">
                <Link
                    v-for="item in items"
                    :key="item.key"
                    :href="item.href"
                    :class="[
                        'personnel-nav-item',
                        {
                            active: props.activeKey
                                ? props.activeKey === item.key
                                : currentPath === item.href,
                        },
                    ]"
                >
                    <component :is="item.icon" :size="18" :stroke-width="1.8" />
                    <span>{{ item.title }}</span>
                    <ArrowRight
                        v-if="
                            props.activeKey
                                ? props.activeKey === item.key
                                : currentPath === item.href
                        "
                        :size="15"
                        class="personnel-nav-arrow"
                    />
                </Link>
            </nav>
            <div class="personnel-side-foot">
                <span class="personnel-live-dot"></span> 与原人事档案同步
                <small>业务数据来自现有数据库</small>
            </div>
        </aside>

        <div class="personnel-main">
            <header class="personnel-topbar">
                <div class="personnel-mobile-brand">人事管理平台</div>
                <div class="personnel-breadcrumb">
                    人事管理 <span>/</span> {{ title }}
                </div>
                <div class="personnel-user">
                    <span class="personnel-avatar">{{
                        userName.slice(0, 1)
                    }}</span>
                    <span>{{ userName }}</span>
                    <Link
                        href="/logout"
                        method="post"
                        as="button"
                        class="personnel-logout"
                        aria-label="退出登录"
                        title="退出登录"
                        ><LogOut :size="17"
                    /></Link>
                </div>
            </header>
            <main class="personnel-content">
                <div class="personnel-page-heading">
                    <div>
                        <p class="personnel-eyebrow">{{ eyebrow }}</p>
                        <h1>{{ title }}</h1>
                    </div>
                    <div class="personnel-heading-line"></div>
                </div>
                <slot />
            </main>
        </div>
    </div>
</template>
