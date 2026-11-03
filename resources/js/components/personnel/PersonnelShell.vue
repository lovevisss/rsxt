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
    ArrowLeftRight,
    ScrollText,
} from '@lucide/vue';

type Module = { menu_id: number; table: string; title: string };
const props = defineProps<{
    modules: Record<string, Module>;
    title: string;
    eyebrow: string;
    activeKey?: string;
    workspace?: 'employee' | 'admin';
}>();
const page = usePage();
const userName = computed(
    () =>
        (page.props.auth as { user?: { name: string } } | undefined)?.user
            ?.name ?? '',
);
const currentPath = computed(() => page.url.split('?')[0]);
const isAdminWorkspace = computed(() => props.workspace === 'admin');
const canSwitchToAdmin = computed(() => !!props.modules.admin_accounts);
const items = computed(() =>
    [
        {
            key: 'admin_home',
            title: '管理工作台',
            href: '/personnel/admin',
            icon: LayoutDashboard,
            visible: isAdminWorkspace.value,
        },
        {
            key: 'dashboard',
            title: '工作台',
            href: '/dashboard',
            icon: LayoutDashboard,
            visible: !isAdminWorkspace.value,
        },
        {
            key: 'profile',
            title: '个人档案',
            href: '/personnel/profile',
            icon: UserRound,
            visible: !isAdminWorkspace.value && !!props.modules.profile,
        },
        {
            key: 'corrections',
            title: '更正申请',
            href: '/personnel/corrections',
            icon: FilePenLine,
            visible: !isAdminWorkspace.value && !!props.modules.corrections,
        },
        {
            key: 'correction_review',
            title: '更正审核',
            href: '/personnel/admin/profile-corrections',
            icon: ShieldCheck,
            visible:
                !isAdminWorkspace.value && !!props.modules.correction_review,
        },
        {
            key: 'history',
            title: '档案履历',
            href: '/personnel/history',
            icon: History,
            visible: !isAdminWorkspace.value && !!props.modules.history,
        },
        {
            key: 'assessments',
            title: '年度考核',
            href: '/personnel/assessments',
            icon: ClipboardCheck,
            visible: !isAdminWorkspace.value && !!props.modules.assessments,
        },
        {
            key: 'assessment_review',
            title: '部门考核审核',
            href: '/personnel/assessments/review',
            icon: ShieldCheck,
            visible:
                !isAdminWorkspace.value && !!props.modules.assessment_review,
        },
        {
            key: 'leave',
            title: '请假申请与进度',
            href: '/personnel/leave',
            icon: CalendarDays,
            visible:
                !isAdminWorkspace.value &&
                (!!props.modules.leave || !!props.modules.leave_progress),
        },
        {
            key: 'leave_details',
            title: '我的请假明细',
            href: '/personnel/leave/details',
            icon: CalendarDays,
            visible: !isAdminWorkspace.value && !!props.modules.leave_details,
        },
        {
            key: 'leave_cancel',
            title: '销假申请',
            href: '/personnel/leave/cancellations',
            icon: CalendarDays,
            visible: !isAdminWorkspace.value && !!props.modules.leave_cancel,
        },
        {
            key: 'leave_review',
            title: '请假审批',
            href: '/personnel/leave/review',
            icon: ShieldCheck,
            visible: !isAdminWorkspace.value && !!props.modules.leave_review,
        },
        {
            key: 'position_admin',
            title: '聘任岗位变动',
            href: '/personnel/admin/positions',
            icon: BriefcaseBusiness,
            visible: isAdminWorkspace.value && !!props.modules.position_admin,
        },
        {
            key: 'grade_admin',
            title: '所聘岗位等级',
            href: '/personnel/admin/grades',
            icon: Layers3,
            visible: isAdminWorkspace.value && !!props.modules.grade_admin,
        },
        {
            key: 'department_admin',
            title: '人员部门变动',
            href: '/personnel/admin/departments',
            icon: Building2,
            visible: isAdminWorkspace.value && !!props.modules.department_admin,
        },
        {
            key: 'admin_accounts',
            title: '后台管理员',
            href: '/personnel/admin/administrators',
            icon: UsersRound,
            visible: isAdminWorkspace.value && !!props.modules.admin_accounts,
        },
        {
            key: 'admin_operations',
            title: '操作记录',
            href: '/personnel/admin/operations',
            icon: ScrollText,
            visible: isAdminWorkspace.value && !!props.modules.admin_operations,
        },
    ].filter((item) => item.visible),
);
</script>

<template>
    <div
        :class="[
            'personnel-app',
            { 'personnel-admin-workspace': isAdminWorkspace },
        ]"
    >
        <aside class="personnel-sidebar">
            <div class="personnel-brand">
                <div class="personnel-brand-mark">
                    <BriefcaseBusiness :size="23" :stroke-width="1.8" />
                </div>
                <div>
                    <span class="personnel-brand-en">{{
                        isAdminWorkspace
                            ? 'PERSONNEL / ADMIN'
                            : 'PERSONNEL / STAFF'
                    }}</span
                    ><strong>{{
                        isAdminWorkspace ? '人事管理后台' : '人事管理平台'
                    }}</strong>
                </div>
            </div>
            <p class="personnel-side-label">
                {{ isAdminWorkspace ? '后台工作空间' : '员工工作空间' }}
            </p>
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
            <a
                v-if="isAdminWorkspace || canSwitchToAdmin"
                class="personnel-workspace-switch"
                :href="isAdminWorkspace ? '/dashboard' : '/personnel/admin'"
                ><ArrowLeftRight :size="17" /><span>{{
                    isAdminWorkspace ? '返回员工工作台' : '进入管理后台'
                }}</span
                ><ArrowRight :size="15"
            /></a>
            <div class="personnel-side-foot">
                <span class="personnel-live-dot"></span> 与原人事档案同步
                <small>业务数据来自现有数据库</small>
            </div>
        </aside>

        <div class="personnel-main">
            <header class="personnel-topbar">
                <div class="personnel-mobile-brand">
                    {{ isAdminWorkspace ? '人事管理后台' : '人事管理平台' }}
                </div>
                <div class="personnel-breadcrumb">
                    {{ isAdminWorkspace ? '管理后台' : '员工工作台' }}
                    <span>/</span> {{ title }}
                </div>
                <div class="personnel-user">
                    <a
                        v-if="isAdminWorkspace || canSwitchToAdmin"
                        class="personnel-topbar-switch"
                        :href="
                            isAdminWorkspace ? '/dashboard' : '/personnel/admin'
                        "
                        >{{ isAdminWorkspace ? '返回前台' : '管理后台' }}</a
                    >
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
