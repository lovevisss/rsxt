<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import PersonnelShell from '@/components/personnel/PersonnelShell.vue';
type Module = { menu_id: number; table: string; title: string };
type Item = {
    record_id: number | string;
    before: Record<string, unknown> | null;
    after: Record<string, unknown> | null;
    details?: unknown;
};
const props = defineProps<{
    modules: Record<string, Module>;
    operation: {
        id: number;
        module: string;
        action: string;
        actor_user_id: number | null;
        actor_name: string | null;
        employee_number: string | null;
        item_count: number;
        status: string;
        created_at: string;
        rolled_back_at: string | null;
        related_operation_id: number | null;
        items: Item[];
        rollback_reason: string | null;
    };
}>();
const rollbackForm = useForm({ rollback: '' });
const labels: Record<string, string> = {
    position: '聘任岗位变动',
    grade: '所聘岗位等级',
    department: '人员部门变动',
    administrators: '后台管理员',
};
function rollback() {
    if (confirm('确定回滚此操作？批量导入会整批回滚。'))
        rollbackForm.post(
            `/personnel/admin/operations/${props.operation.id}/rollback`,
        );
}
</script>
<template>
    <Head title="操作详情" />
    <PersonnelShell
        workspace="admin"
        :modules="modules"
        title="操作详情"
        eyebrow="ADMINISTRATION / 操作追溯"
        active-key="admin_operations"
    >
        <Link class="position-back" href="/personnel/admin/operations"
            >返回操作记录</Link
        >
        <div class="position-lead operation-detail-lead">
            <div>
                <span class="position-lead-kicker"
                    >OPERATION #{{ operation.id }}</span
                >
                <h2>
                    {{ labels[operation.module] || operation.module }} ·
                    {{ operation.action }}
                </h2>
                <p>
                    {{ operation.created_at }} · 操作人
                    {{
                        operation.actor_name ||
                        operation.actor_user_id ||
                        '系统'
                    }}
                    · {{ operation.item_count }} 条记录
                </p>
            </div>
            <button
                v-if="!operation.rollback_reason"
                type="button"
                class="operation-rollback"
                @click="rollback"
            >
                回滚此操作
            </button>
        </div>
        <div v-if="operation.rollback_reason" class="position-preview-alert">
            {{ operation.rollback_reason }}
        </div>
        <div v-if="rollbackForm.errors.rollback" class="position-preview-alert">
            {{ rollbackForm.errors.rollback }}
        </div>
        <p v-if="operation.rolled_back_at" class="operation-meta">
            回滚时间：{{ operation.rolled_back_at }}
        </p>
        <p v-if="operation.related_operation_id" class="operation-meta">
            关联原操作：<Link
                :href="`/personnel/admin/operations/${operation.related_operation_id}`"
                >#{{ operation.related_operation_id }}</Link
            >
        </p>
        <section
            v-for="(item, index) in operation.items"
            :key="index"
            class="operation-item"
        >
            <h3>记录 {{ item.record_id || index + 1 }}</h3>
            <div class="operation-snapshots">
                <div>
                    <h4>修改前</h4>
                    <pre>{{
                        item.before
                            ? JSON.stringify(item.before, null, 2)
                            : '无（新增）'
                    }}</pre>
                </div>
                <div>
                    <h4>修改后</h4>
                    <pre>{{
                        item.after
                            ? JSON.stringify(item.after, null, 2)
                            : '无（删除）'
                    }}</pre>
                </div>
            </div>
            <pre v-if="item.details">历史日志：{{ item.details }}</pre>
        </section>
    </PersonnelShell>
</template>
