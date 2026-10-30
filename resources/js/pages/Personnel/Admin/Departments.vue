<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import {
    ArrowDownToLine,
    ArrowUpFromLine,
    FileSpreadsheet,
    Pencil,
    Plus,
    Search,
    Trash2,
    X,
} from '@lucide/vue';
import PersonnelShell from '@/components/personnel/PersonnelShell.vue';

type Module = { menu_id: number; table: string; title: string };
type Department = { code: string; label: string };
type DepartmentRecord = {
    id: number;
    name: string;
    employee_number: string;
    source_department: string;
    source_label: string;
    target_department: string;
    target_label: string;
    changed_at: string;
    reason: string | null;
    note: string | null;
    version: string;
};
const props = defineProps<{
    modules: Record<string, Module>;
    records: {
        data: DepartmentRecord[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    filters: { department?: string; search?: string };
    departments: Department[];
    writerAvailable: boolean;
}>();
const department = ref(props.filters.department || '');
const search = ref(props.filters.search || '');
const selected = ref<DepartmentRecord | null>(null);
const showForm = ref(false);
const personName = ref('');
const lookupMessage = ref('');
const form = useForm({
    employee_number: '',
    source_department: '',
    target_department: '',
    changed_at: '',
    reason: '',
    note: '',
    version: '',
});
const upload = useForm<{ file: File | null }>({ file: null });
const exportUrl = computed(
    () =>
        `/personnel/admin/departments/export?${new URLSearchParams({ department: department.value, search: search.value })}`,
);
function applyFilters() {
    router.get(
        '/personnel/admin/departments',
        { department: department.value, search: search.value },
        { preserveState: true },
    );
}
function openCreate() {
    selected.value = null;
    personName.value = '';
    lookupMessage.value = '';
    form.reset();
    showForm.value = true;
}
function openEdit(item: DepartmentRecord) {
    selected.value = item;
    personName.value = item.name;
    lookupMessage.value = '';
    form.employee_number = item.employee_number;
    form.source_department = item.source_department;
    form.target_department = item.target_department;
    form.changed_at = item.changed_at;
    form.reason = item.reason || '';
    form.note = item.note || '';
    form.version = item.version;
    showForm.value = true;
}
watch(
    () => form.employee_number,
    async (number) => {
        if (!showForm.value || selected.value || !number.trim()) return;
        personName.value = '';
        lookupMessage.value = '';
        try {
            const response = await fetch(
                `/personnel/admin/departments/person?employee_number=${encodeURIComponent(number.trim())}`,
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                },
            );
            if (!response.ok) throw new Error('找不到有效职工档案或启用账号');
            const person = (await response.json()) as {
                name: string;
                department: string;
            };
            if (form.employee_number !== number) return;
            personName.value = person.name;
            form.source_department = person.department;
        } catch {
            if (form.employee_number === number)
                lookupMessage.value = '找不到有效职工档案或启用账号';
        }
    },
);
function save() {
    const options = {
        onSuccess: () => {
            showForm.value = false;
        },
    };
    if (selected.value)
        form.put(`/personnel/admin/departments/${selected.value.id}`, options);
    else form.post('/personnel/admin/departments', options);
}
function remove(item: DepartmentRecord) {
    if (confirm(`删除 ${item.name} 在 ${item.changed_at} 的部门变动记录？`))
        router.delete(`/personnel/admin/departments/${item.id}`, {
            data: { version: item.version },
        });
}
</script>

<template>
    <Head title="人员部门变动" />
    <PersonnelShell
        :modules="modules"
        title="人员部门变动"
        eyebrow="PERSONNEL / 部门履历"
        active-key="department_admin"
    >
        <div class="position-lead department-lead">
            <div>
                <span class="position-lead-kicker">DEPARTMENT TRANSFERS</span>
                <h2>部门流转，按日期生效。</h2>
                <p>维护部门变动历史，并同步人员档案与门户账号的当前部门。</p>
            </div>
            <div class="department-lead-count">
                <strong>{{ records.total }}</strong
                ><span>条筛选结果</span>
            </div>
        </div>
        <div class="position-actions">
            <div class="department-action-note">
                原部门 · 目标部门 · 生效日期
            </div>
            <div class="position-action-links">
                <a href="/personnel/admin/departments/template"
                    ><FileSpreadsheet :size="16" />下载模板</a
                ><a :href="exportUrl"
                    ><ArrowDownToLine :size="16" />导出 Excel</a
                ><button
                    v-if="writerAvailable"
                    type="button"
                    class="position-primary"
                    @click="openCreate"
                >
                    <Plus :size="17" />新增变动
                </button>
            </div>
        </div>
        <section class="position-panel">
            <div class="position-filters">
                <label
                    ><span>原部门</span
                    ><select v-model="department" @change="applyFilters">
                        <option value="">全部部门</option>
                        <option
                            v-for="item in departments"
                            :key="item.code"
                            :value="item.code"
                        >
                            {{ item.label }}
                        </option>
                    </select></label
                ><label
                    ><span>姓名 / 工号</span>
                    <div class="position-search">
                        <Search :size="16" /><input
                            v-model="search"
                            placeholder="输入姓名或工号"
                            @keyup.enter="applyFilters"
                        /></div></label
                ><button type="button" @click="applyFilters">查询</button>
            </div>
            <div class="position-table-wrap">
                <table class="department-table">
                    <thead>
                        <tr>
                            <th>人员</th>
                            <th>原部门</th>
                            <th>目标部门</th>
                            <th>变动日期</th>
                            <th>变动原因</th>
                            <th>备注二</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in records.data" :key="item.id">
                            <td>
                                <strong>{{ item.name }}</strong
                                ><small>{{ item.employee_number }}</small>
                            </td>
                            <td>{{ item.source_label || '—' }}</td>
                            <td>
                                <span class="department-target">{{
                                    item.target_label || '—'
                                }}</span>
                            </td>
                            <td>{{ item.changed_at || '—' }}</td>
                            <td>{{ item.reason || '—' }}</td>
                            <td>{{ item.note || '—' }}</td>
                            <td>
                                <div class="position-row-actions">
                                    <button
                                        v-if="writerAvailable"
                                        type="button"
                                        aria-label="编辑"
                                        @click="openEdit(item)"
                                    >
                                        <Pencil :size="15" /></button
                                    ><button
                                        v-if="writerAvailable"
                                        type="button"
                                        aria-label="删除"
                                        @click="remove(item)"
                                    >
                                        <Trash2 :size="15" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!records.data.length">
                            <td colspan="7" class="position-empty">
                                当前筛选条件下暂无记录
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
        <section class="position-import">
            <div>
                <span class="position-section-tag">BULK IMPORT</span>
                <h3>批量导入部门变动</h3>
                <p>
                    按模板填写工号、部门代码和变动日期。上传后先预览；错误行会阻止整批提交。
                </p>
            </div>
            <form
                v-if="writerAvailable"
                @submit.prevent="
                    upload.post('/personnel/admin/departments/import/preview')
                "
            >
                <input
                    type="file"
                    accept=".xlsx"
                    @change="
                        upload.file =
                            ($event.target as HTMLInputElement).files?.[0] ||
                            null
                    "
                /><button
                    :disabled="upload.processing || !upload.file"
                    type="submit"
                >
                    <ArrowUpFromLine :size="16" />预览导入</button
                ><small v-if="upload.errors.file">{{
                    upload.errors.file
                }}</small>
            </form>
            <p v-else class="position-disabled">
                写入账号尚未配置，当前仅可查询与导出。
            </p>
        </section>
        <div
            v-if="showForm"
            class="position-overlay"
            @click.self="showForm = false"
        >
            <section
                class="position-drawer department-drawer"
                role="dialog"
                aria-modal="true"
                aria-label="部门变动表单"
            >
                <div class="position-drawer-head">
                    <div>
                        <span class="position-section-tag"
                            >DEPARTMENT RECORD</span
                        >
                        <h2>
                            {{ selected ? '编辑部门变动' : '新增部门变动' }}
                        </h2>
                    </div>
                    <button
                        type="button"
                        aria-label="关闭"
                        @click="showForm = false"
                    >
                        <X :size="19" />
                    </button>
                </div>
                <form @submit.prevent="save">
                    <label
                        >职工编号<input
                            v-model="form.employee_number"
                            required
                            placeholder="输入有效职工编号"
                    /></label>
                    <p v-if="personName" class="department-person">
                        {{ personName }} · 已找到有效档案
                    </p>
                    <p v-if="lookupMessage" class="department-lookup-error">
                        {{ lookupMessage }}
                    </p>
                    <label
                        >原部门<select
                            v-model="form.source_department"
                            required
                        >
                            <option value="">请选择原部门</option>
                            <option
                                v-if="
                                    selected &&
                                    !departments.some(
                                        (item) =>
                                            item.code ===
                                            selected?.source_department,
                                    )
                                "
                                :value="selected.source_department"
                            >
                                历史代码 · {{ selected.source_department }}
                            </option>
                            <option
                                v-for="item in departments"
                                :key="item.code"
                                :value="item.code"
                            >
                                {{ item.label }} · {{ item.code }}
                            </option>
                        </select></label
                    >
                    <label
                        >目标部门<select
                            v-model="form.target_department"
                            required
                        >
                            <option value="">请选择目标部门</option>
                            <option
                                v-if="
                                    selected &&
                                    !departments.some(
                                        (item) =>
                                            item.code ===
                                            selected?.target_department,
                                    )
                                "
                                :value="selected.target_department"
                            >
                                历史代码 · {{ selected.target_department }}
                            </option>
                            <option
                                v-for="item in departments"
                                :key="item.code"
                                :value="item.code"
                            >
                                {{ item.label }} · {{ item.code }}
                            </option>
                        </select></label
                    >
                    <label
                        >变动日期<input
                            v-model="form.changed_at"
                            type="date"
                            required /></label
                    ><label
                        >变动原因<input
                            v-model="form.reason"
                            maxlength="255" /></label
                    ><label
                        >备注二<textarea
                            v-model="form.note"
                            maxlength="255"
                            rows="3"
                        />
                    </label>
                    <div
                        v-if="Object.keys(form.errors).length"
                        class="position-errors"
                    >
                        <p v-for="(message, field) in form.errors" :key="field">
                            {{ message }}
                        </p>
                    </div>
                    <button
                        class="position-save"
                        type="submit"
                        :disabled="form.processing"
                    >
                        保存部门变动
                    </button>
                </form>
            </section>
        </div>
    </PersonnelShell>
</template>
