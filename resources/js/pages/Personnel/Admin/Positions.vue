<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
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
type RecordItem = {
    id: number;
    type: string;
    employee_number: string;
    name: string;
    department: string;
    old_position: string;
    old_position_label: string;
    old_date: string | null;
    new_position: string;
    new_position_label: string;
    new_date: string;
    reason: string | null;
    note: string | null;
    version: string;
};
const props = defineProps<{
    modules: Record<string, Module>;
    records: {
        data: RecordItem[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    filters: { type?: string; department?: string; search?: string };
    positions: { code: string; label: string }[];
    departments: { code: string; label: string }[];
    writerAvailable: boolean;
}>();
const type = ref(props.filters.type || 'DF');
const department = ref(props.filters.department || '');
const search = ref(props.filters.search || '');
const activeRecord = ref<RecordItem | null>(null);
const showForm = ref(false);
const upload = useForm<{ file: File | null }>({ file: null });
const form = useForm({
    type: 'DF',
    employee_number: '',
    old_position: '',
    old_date: '',
    new_position: '',
    new_date: '',
    reason: '',
    note: '',
    version: '',
});
const exportUrl = computed(
    () =>
        `/personnel/admin/positions/export?${new URLSearchParams({ type: type.value, department: department.value, search: search.value })}`,
);
function applyFilters() {
    router.get(
        '/personnel/admin/positions',
        {
            type: type.value,
            department: department.value,
            search: search.value,
        },
        { preserveState: true },
    );
}
function selectType(value: string) {
    type.value = value;
    applyFilters();
}
function openCreate() {
    activeRecord.value = null;
    form.reset();
    form.type = type.value;
    showForm.value = true;
}
function openEdit(record: RecordItem) {
    activeRecord.value = record;
    form.type = record.type;
    form.employee_number = record.employee_number;
    form.old_position = record.old_position;
    form.old_date = record.old_date || '';
    form.new_position = record.new_position;
    form.new_date = record.new_date;
    form.reason = record.reason || '';
    form.note = record.note || '';
    form.version = record.version;
    showForm.value = true;
}
function save() {
    if (activeRecord.value)
        form.put(`/personnel/admin/positions/${activeRecord.value.id}`, {
            onSuccess: () => {
                showForm.value = false;
            },
        });
    else
        form.post('/personnel/admin/positions', {
            onSuccess: () => {
                showForm.value = false;
            },
        });
}
function remove(record: RecordItem) {
    if (confirm(`删除 ${record.name} 的这条岗位变动记录？`))
        router.delete(`/personnel/admin/positions/${record.id}`, {
            data: { version: record.version },
        });
}
function preview() {
    upload.post('/personnel/admin/positions/import/preview');
}
const departmentName = (code: string) =>
    props.departments.find((item) => item.code === code)?.label || code || '—';
</script>

<template>
    <Head title="聘任岗位变动" />
    <PersonnelShell
        :modules="modules"
        title="聘任岗位变动"
        eyebrow="PERSONNEL / 岗位履历"
        active-key="position_admin"
    >
        <div class="position-lead">
            <div>
                <span class="position-lead-kicker">APPOINTMENT CHANGES</span>
                <h2>岗位变化，清楚可追溯。</h2>
                <p>分开管理东方与本部记录，按最新聘任时间同步人员档案。</p>
            </div>
            <div class="position-lead-count">
                <strong>{{ records.total }}</strong
                ><span>条筛选结果</span>
            </div>
        </div>
        <div class="position-actions">
            <div class="position-tabs">
                <button
                    v-for="option in [
                        { value: 'DF', label: '东方' },
                        { value: 'BB', label: '本部' },
                    ]"
                    :key="option.value"
                    type="button"
                    :class="{ active: type === option.value }"
                    @click="selectType(option.value)"
                >
                    {{ option.label }}
                </button>
            </div>
            <div class="position-action-links">
                <a href="/personnel/admin/positions/template"
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
                    ><span>所在部门</span
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
                <table>
                    <thead>
                        <tr>
                            <th>人员</th>
                            <th>部门</th>
                            <th>原聘岗位 · 时间</th>
                            <th>现聘岗位 · 时间</th>
                            <th>变动原因</th>
                            <th>备注</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="record in records.data" :key="record.id">
                            <td>
                                <strong>{{ record.name }}</strong
                                ><small>{{ record.employee_number }}</small>
                            </td>
                            <td>{{ departmentName(record.department) }}</td>
                            <td>
                                {{ record.old_position_label
                                }}<small>{{
                                    record.old_date || '未填写'
                                }}</small>
                            </td>
                            <td>
                                <strong>{{ record.new_position_label }}</strong
                                ><small>{{ record.new_date }}</small>
                            </td>
                            <td>{{ record.reason || '—' }}</td>
                            <td>{{ record.note || '—' }}</td>
                            <td>
                                <div class="position-row-actions">
                                    <button
                                        v-if="writerAvailable"
                                        type="button"
                                        aria-label="编辑"
                                        @click="openEdit(record)"
                                    >
                                        <Pencil :size="15" /></button
                                    ><button
                                        v-if="writerAvailable"
                                        type="button"
                                        aria-label="删除"
                                        @click="remove(record)"
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
                <h3>批量导入</h3>
                <p>
                    按模板填写后上传。系统先逐行校验，确认后才会写入岗位变动和人员档案。
                </p>
            </div>
            <form v-if="writerAvailable" @submit.prevent="preview">
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
                岗位写入账号尚未配置，当前仅可查询与导出。
            </p>
        </section>
        <div
            v-if="showForm"
            class="position-overlay"
            @click.self="showForm = false"
        >
            <section
                class="position-drawer"
                role="dialog"
                aria-modal="true"
                aria-label="岗位变动表单"
            >
                <div class="position-drawer-head">
                    <div>
                        <span class="position-section-tag"
                            >POSITION RECORD</span
                        >
                        <h2>
                            {{ activeRecord ? '编辑岗位变动' : '新增岗位变动' }}
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
                        >类型<select v-model="form.type" required>
                            <option value="DF">东方</option>
                            <option value="BB">本部</option>
                        </select></label
                    ><label
                        >职工编号<input
                            v-model="form.employee_number"
                            required
                            placeholder="输入有效职工编号" /></label
                    ><label
                        >原聘岗位<select v-model="form.old_position" required>
                            <option value="">请选择</option>
                            <option
                                v-for="item in positions"
                                :key="item.code"
                                :value="item.code"
                            >
                                {{ item.label }} · {{ item.code }}
                            </option>
                        </select></label
                    ><label
                        >原聘任时间<input
                            v-model="form.old_date"
                            type="date" /></label
                    ><label
                        >现聘岗位<select v-model="form.new_position" required>
                            <option value="">请选择</option>
                            <option
                                v-for="item in positions"
                                :key="item.code"
                                :value="item.code"
                            >
                                {{ item.label }} · {{ item.code }}
                            </option>
                        </select></label
                    ><label
                        >现聘任时间<input
                            v-model="form.new_date"
                            type="date"
                            required /></label
                    ><label
                        >变动原因<input
                            v-model="form.reason"
                            maxlength="255" /></label
                    ><label
                        >备注<textarea
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
                        保存变动
                    </button>
                </form>
            </section>
        </div>
    </PersonnelShell>
</template>
