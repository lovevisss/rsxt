<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import PersonnelShell from '@/components/personnel/PersonnelShell.vue';
type Module = { menu_id: number; table: string; title: string };
type Row = {
    line: number;
    status: 'ready' | 'duplicate' | 'error';
    error: string | null;
    row: {
        employee_number: string;
        source_department: string;
        target_department: string;
        changed_at: string;
        reason: string | null;
    };
};
const props = defineProps<{
    modules: Record<string, Module>;
    rows: Row[];
    token: string;
}>();
const form = useForm({ token: props.token });
const errors = props.rows.filter((row) => row.status === 'error').length;
const ready = props.rows.filter((row) => row.status === 'ready').length;
const duplicate = props.rows.filter((row) => row.status === 'duplicate').length;
</script>
<template>
    <Head title="部门变动导入预览" />
    <PersonnelShell
        :modules="modules"
        title="部门变动导入预览"
        eyebrow="BULK IMPORT / 逐行校验"
        active-key="department_admin"
    >
        <div class="position-lead department-lead">
            <div>
                <span class="position-lead-kicker"
                    >DEPARTMENT IMPORT REVIEW</span
                >
                <h2>确认变动明细。</h2>
                <p>
                    可导入 {{ ready }} 条 · 重复跳过 {{ duplicate }} 条 · 错误
                    {{ errors }} 条
                </p>
            </div>
            <Link class="position-back" href="/personnel/admin/departments"
                >返回列表</Link
            >
        </div>
        <div v-if="errors" class="position-preview-alert">
            请按行号修正错误后重新上传。当前文件不会写入任何记录。
        </div>
        <section class="position-panel">
            <div class="position-table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>行号</th>
                            <th>状态</th>
                            <th>工号</th>
                            <th>原部门</th>
                            <th>目标部门</th>
                            <th>变动日期</th>
                            <th>原因</th>
                            <th>说明</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.line">
                            <td>{{ row.line }}</td>
                            <td>
                                <span
                                    :class="['position-status', row.status]"
                                    >{{
                                        row.status === 'ready'
                                            ? '待导入'
                                            : row.status === 'duplicate'
                                              ? '重复跳过'
                                              : '错误'
                                    }}</span
                                >
                            </td>
                            <td>{{ row.row.employee_number }}</td>
                            <td>{{ row.row.source_department || '—' }}</td>
                            <td>{{ row.row.target_department || '—' }}</td>
                            <td>{{ row.row.changed_at || '—' }}</td>
                            <td>{{ row.row.reason || '—' }}</td>
                            <td>{{ row.error || '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
        <form
            v-if="!errors && rows.length"
            class="position-commit"
            @submit.prevent="
                form.post('/personnel/admin/departments/import/commit')
            "
        >
            <button type="submit" :disabled="form.processing">
                确认导入 {{ ready }} 条
            </button>
        </form>
    </PersonnelShell>
</template>
