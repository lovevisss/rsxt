<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import {
    ArrowDownToLine,
    ArrowUpFromLine,
    FileSpreadsheet,
    Layers3,
    Pencil,
    Plus,
    Search,
    Trash2,
    X,
} from '@lucide/vue';
import PersonnelShell from '@/components/personnel/PersonnelShell.vue';

type Module = { menu_id: number; table: string; title: string };
type GradeRecord = {
    id: number;
    sequence: string;
    name: string;
    employee_number: string;
    department: string;
    bb_grade: string | null;
    bb_label: string | null;
    bb_start: string | null;
    bb_end: string | null;
    df_grade: string | null;
    df_label: string | null;
    df_start: string | null;
    df_end: string | null;
    note: string | null;
    version: string;
};
const props = defineProps<{
    modules: Record<string, Module>;
    records: {
        data: GradeRecord[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    filters: { department?: string; search?: string };
    grades: { code: string; label: string }[];
    departments: { code: string; label: string }[];
    writerAvailable: boolean;
}>();
const department = ref(props.filters.department || '');
const search = ref(props.filters.search || '');
const selected = ref<GradeRecord | null>(null);
const showForm = ref(false);
const form = useForm({
    employee_number: '',
    bb_grade: '',
    bb_start: '',
    bb_end: '',
    df_grade: '',
    df_start: '',
    df_end: '',
    note: '',
    version: '',
});
const upload = useForm<{ file: File | null }>({ file: null });
const exportUrl = computed(
    () =>
        `/personnel/admin/grades/export?${new URLSearchParams({ department: department.value, search: search.value })}`,
);
const departmentName = (code: string) =>
    props.departments.find((item) => item.code === code)?.label || code || '—';
function applyFilters() {
    router.get(
        '/personnel/admin/grades',
        { department: department.value, search: search.value },
        { preserveState: true },
    );
}
function openCreate() {
    selected.value = null;
    form.reset();
    showForm.value = true;
}
function openEdit(item: GradeRecord) {
    selected.value = item;
    form.employee_number = item.employee_number;
    form.bb_grade = item.bb_grade || '';
    form.bb_start = item.bb_start || '';
    form.bb_end = item.bb_end || '';
    form.df_grade = item.df_grade || '';
    form.df_start = item.df_start || '';
    form.df_end = item.df_end || '';
    form.note = item.note || '';
    form.version = item.version;
    showForm.value = true;
}
function save() {
    if (selected.value)
        form.put(`/personnel/admin/grades/${selected.value.id}`, {
            onSuccess: () => {
                showForm.value = false;
            },
        });
    else
        form.post('/personnel/admin/grades', {
            onSuccess: () => {
                showForm.value = false;
            },
        });
}
function remove(item: GradeRecord) {
    if (confirm(`删除 ${item.name} 的这条岗位等级记录？`))
        router.delete(`/personnel/admin/grades/${item.id}`, {
            data: { version: item.version },
        });
}
function preview() {
    upload.post('/personnel/admin/grades/import/preview');
}
</script>

<template>
    <Head title="所聘岗位等级" />
    <PersonnelShell
        :modules="modules"
        title="所聘岗位等级"
        eyebrow="PERSONNEL / 等级履历"
        active-key="grade_admin"
    >
        <div class="position-lead grade-lead">
            <div>
                <span class="position-lead-kicker">APPOINTMENT GRADES</span>
                <h2>两地等级，一处管理。</h2>
                <p>
                    保留本部与东方各自的聘任起止时间，当前等级按有效记录更新。
                </p>
            </div>
            <div class="grade-lead-mark">
                <Layers3 :size="34" /><strong>{{ records.total }}</strong
                ><span>条筛选结果</span>
            </div>
        </div>
        <div class="position-actions">
            <div class="grade-view-label">
                <span>本部</span><span>东方</span
                ><small>同一记录 · 双栏呈现</small>
            </div>
            <div class="position-action-links">
                <a href="/personnel/admin/grades/template"
                    ><FileSpreadsheet :size="16" />下载模板</a
                ><a :href="exportUrl"
                    ><ArrowDownToLine :size="16" />导出 Excel</a
                ><button
                    v-if="writerAvailable"
                    type="button"
                    class="position-primary"
                    @click="openCreate"
                >
                    <Plus :size="17" />新增等级记录
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
                <table class="grade-table">
                    <thead>
                        <tr>
                            <th>序号 / 人员</th>
                            <th>部门</th>
                            <th>本部所聘等级</th>
                            <th>本部聘任时间</th>
                            <th>本部结束时间</th>
                            <th>东方所聘等级</th>
                            <th>东方聘任时间</th>
                            <th>东方结束时间</th>
                            <th>备注</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in records.data" :key="item.id">
                            <td>
                                <small class="grade-sequence"
                                    >NO. {{ item.sequence }}</small
                                ><strong>{{ item.name }}</strong
                                ><small>{{ item.employee_number }}</small>
                            </td>
                            <td>{{ departmentName(item.department) }}</td>
                            <td>
                                <span
                                    v-if="item.bb_grade"
                                    class="grade-badge bb"
                                    >{{ item.bb_label }}</span
                                ><span v-else class="grade-blank">—</span>
                            </td>
                            <td>{{ item.bb_start || '—' }}</td>
                            <td>{{ item.bb_end || '—' }}</td>
                            <td>
                                <span
                                    v-if="item.df_grade"
                                    class="grade-badge df"
                                    >{{ item.df_label }}</span
                                ><span v-else class="grade-blank">—</span>
                            </td>
                            <td>{{ item.df_start || '—' }}</td>
                            <td>{{ item.df_end || '—' }}</td>
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
                            <td colspan="10" class="position-empty">
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
                <h3>批量导入等级记录</h3>
                <p>
                    按模板填写一地或两地等级，上传后逐行预览。结束时间到期的记录仍保留在历史中。
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
                class="position-drawer grade-drawer"
                role="dialog"
                aria-modal="true"
                aria-label="岗位等级表单"
            >
                <div class="position-drawer-head">
                    <div>
                        <span class="position-section-tag">GRADE RECORD</span>
                        <h2>
                            {{ selected ? '编辑等级记录' : '新增等级记录' }}
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
                    <label class="grade-full"
                        >职工编号<input
                            v-model="form.employee_number"
                            required
                            placeholder="输入有效职工编号"
                    /></label>
                    <div class="grade-form-group">
                        <div class="grade-form-group-head bb">
                            <span>01 / 本部</span><small>可留空</small>
                        </div>
                        <div class="grade-form-fields">
                            <label
                                >所聘岗位等级<select v-model="form.bb_grade">
                                    <option value="">未填写</option>
                                    <option
                                        v-for="item in grades"
                                        :key="item.code"
                                        :value="item.code"
                                    >
                                        {{ item.label }} · {{ item.code }}
                                    </option>
                                </select></label
                            ><label
                                >聘任时间<input
                                    v-model="form.bb_start"
                                    type="date" /></label
                            ><label
                                >聘任结束时间<input
                                    v-model="form.bb_end"
                                    type="date"
                            /></label>
                        </div>
                    </div>
                    <div class="grade-form-group">
                        <div class="grade-form-group-head df">
                            <span>02 / 东方</span><small>可留空</small>
                        </div>
                        <div class="grade-form-fields">
                            <label
                                >所聘岗位等级<select v-model="form.df_grade">
                                    <option value="">未填写</option>
                                    <option
                                        v-for="item in grades"
                                        :key="item.code"
                                        :value="item.code"
                                    >
                                        {{ item.label }} · {{ item.code }}
                                    </option>
                                </select></label
                            ><label
                                >聘任时间<input
                                    v-model="form.df_start"
                                    type="date" /></label
                            ><label
                                >聘任结束时间<input
                                    v-model="form.df_end"
                                    type="date"
                            /></label>
                        </div>
                    </div>
                    <label class="grade-full"
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
                        保存等级记录
                    </button>
                </form>
            </section>
        </div>
    </PersonnelShell>
</template>
