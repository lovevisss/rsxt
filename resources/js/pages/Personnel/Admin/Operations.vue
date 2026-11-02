<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import PersonnelShell from '@/components/personnel/PersonnelShell.vue';
type Module = { menu_id: number; table: string; title: string };
type Operation = {
    id: number;
    module: string;
    action: string;
    actor: string;
    employee_number: string | null;
    item_count: number;
    status: string;
    created_at: string;
};
const props = defineProps<{
    modules: Record<string, Module>;
    records: {
        data: Operation[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    filters: Record<string, string>;
}>();
const filters = reactive({
    module: props.filters.module || '',
    action: props.filters.action || '',
    actor: props.filters.actor || '',
    employee_number: props.filters.employee_number || '',
    from: props.filters.from || '',
    to: props.filters.to || '',
});
const moduleLabels: Record<string, string> = {
    position: '聘任岗位变动',
    grade: '所聘岗位等级',
    department: '人员部门变动',
    administrators: '后台管理员',
};
const actions: Record<string, string> = {
    created: '新增',
    imported: '批量导入',
    updated: '编辑',
    deleted: '删除',
    admin_added: '添加管理员',
    admin_removed: '移除管理员',
    rollback: '回滚',
    scheduled_refresh: '定时刷新',
};
const statuses: Record<string, string> = {
    active: '可检查回滚',
    legacy: '历史只读',
    rolled_back: '已回滚',
    rollback: '回滚记录',
};
function search() {
    router.get('/personnel/admin/operations', filters);
}
</script>
<template>
    <Head title="管理员操作记录" />
    <PersonnelShell
        workspace="admin"
        :modules="props.modules"
        title="操作记录"
        eyebrow="ADMINISTRATION / 操作追溯"
        active-key="admin_operations"
    >
        <div class="position-lead">
            <div>
                <span class="position-lead-kicker">ACTIVITY & ROLLBACK</span>
                <h2>每一次操作，都有据可查。</h2>
                <p>查看修改前后内容；有后续冲突时，系统会阻止回滚。</p>
            </div>
        </div>
        <section class="position-panel">
            <form class="operation-filters" @submit.prevent="search">
                <label
                    >业务<select v-model="filters.module">
                        <option value="">全部</option>
                        <option
                            v-for="(label, key) in moduleLabels"
                            :key="key"
                            :value="key"
                        >
                            {{ label }}
                        </option>
                    </select></label
                ><label
                    >动作<select v-model="filters.action">
                        <option value="">全部</option>
                        <option
                            v-for="(label, key) in actions"
                            :key="key"
                            :value="key"
                        >
                            {{ label }}
                        </option>
                    </select></label
                ><label
                    >操作人<input
                        v-model="filters.actor"
                        placeholder="姓名或工号" /></label
                ><label
                    >职工编号<input
                        v-model="filters.employee_number"
                        placeholder="工号" /></label
                ><label
                    >开始日期<input v-model="filters.from" type="date" /></label
                ><label
                    >结束日期<input v-model="filters.to" type="date" /></label
                ><button type="submit">查询</button>
            </form>
            <div class="position-table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>时间</th>
                            <th>业务</th>
                            <th>动作</th>
                            <th>操作人</th>
                            <th>工号</th>
                            <th>记录数</th>
                            <th>状态</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in records.data" :key="item.id">
                            <td>{{ item.created_at }}</td>
                            <td>
                                {{ moduleLabels[item.module] || item.module }}
                            </td>
                            <td>{{ actions[item.action] || item.action }}</td>
                            <td>{{ item.actor }}</td>
                            <td>{{ item.employee_number || '—' }}</td>
                            <td>{{ item.item_count }}</td>
                            <td>{{ statuses[item.status] || item.status }}</td>
                            <td>
                                <Link
                                    :href="`/personnel/admin/operations/${item.id}`"
                                    >查看详情</Link
                                >
                            </td>
                        </tr>
                        <tr v-if="!records.data.length">
                            <td colspan="8" class="position-empty">
                                暂无操作记录
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="records.links.length > 3" class="position-pages">
                <Link
                    v-for="(link, index) in records.links"
                    :key="index"
                    :href="link.url || '#'"
                    :class="{ active: link.active, disabled: !link.url }"
                    v-html="link.label"
                />
            </div>
        </section>
    </PersonnelShell>
</template>
