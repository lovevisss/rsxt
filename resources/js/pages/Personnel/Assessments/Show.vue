<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import { ArrowLeft, Download, Save, Send, Undo2 } from '@lucide/vue';
import PersonnelShell from '@/components/personnel/PersonnelShell.vue';
import '@/../css/assessments.css';

type Module = { menu_id: number; table: string; title: string };
type Field = { key: string; label: string; value: string };
type Event = { action: string; note: string | null; result_code: string | null; created_at: string };
const props = defineProps<{
    modules: Record<string, Module>;
    record: { id: number; year: string; name: string; status: string; version: string; summary: string; fields: Field[] };
    events: Event[];
    resultOptions: { code: string; label: string }[];
    canEdit: boolean; canWithdraw: boolean; canReview: boolean; reviewerView: boolean; writerAvailable: boolean; readOnlyPreview: boolean;
}>();
const page = usePage();
const busy = ref(false);
const note = ref('');
const result = ref('');
const editMode = ref(false);
const editor = useEditor({ content: props.record.summary, extensions: [StarterKit] });
watch(() => props.record.summary, (html) => { if (!editMode.value) editor.value?.commands.setContent(html); });
const canWrite = computed(() => props.writerAvailable && !props.readOnlyPreview);
const flash = computed(() => (page.props.flash as { success?: string } | undefined)?.success);
const errors = computed(() => page.props.errors as Record<string, string>);
const eventNames: Record<string, string> = { saved: '保存总结', submitted: '提交部门审核', withdrawn: '撤销提交', approved: '部门审核完成', returned: '部门退回' };
const base = computed(() => `/personnel/assessments/${props.record.id}`);

function post(action: string, data: Record<string, string> = {}) {
    if (busy.value) return;
    busy.value = true;
    router.post(`${base.value}/${action}`, { version: props.record.version, ...data }, {
        preserveScroll: true,
        onSuccess: () => { if (action === 'summary') editMode.value = false; },
        onFinish: () => { busy.value = false; },
    });
}
function save() { post('summary', { summary: editor.value?.getHTML() || '' }); }
function submit() { post('submit'); }
function withdraw() { if (confirm('确认撤销提交？撤销后可以继续编辑年度总结。')) post('withdraw'); }
function approve() { if (result.value && confirm('确认完成部门考核？')) post('approve', { result: result.value }); }
function returnForChanges() { if (note.value.trim() && confirm('确认退回员工修改？')) post('return', { note: note.value.trim() }); }
</script>

<template>
    <Head :title="`${record.year} 年度考核`" />
    <PersonnelShell :modules="modules" :title="`${record.year} 年度考核`" eyebrow="ASSESSMENT / DETAIL" :active-key="reviewerView ? 'assessment_review' : 'assessments'">
        <div class="assessment-detail-actions"><Link :href="reviewerView ? '/personnel/assessments/review' : '/personnel/assessments'"><ArrowLeft :size="16" />返回列表</Link><a :href="`${base}/word`" class="assessment-export"><Download :size="16" />导出 Word</a></div>
        <div v-if="flash" class="assessment-message">{{ flash }}</div>
        <div v-if="readOnlyPreview" class="assessment-warning">本机预览仅供查看，不能修改或审核考核记录。</div>
        <div v-else-if="!writerAvailable && (canEdit || canWithdraw || canReview)" class="assessment-warning">年度考核写入尚未启用，当前可查看详情和导出 Word。</div>
        <section class="assessment-card assessment-hero"><div><small>PERSONAL ASSESSMENT</small><h2>{{ record.name }} · {{ record.year }} 年度</h2></div><span class="assessment-status">{{ record.status }}</span></section>
        <section class="assessment-card"><h3>个人信息与考核结果</h3><div class="assessment-fields"><div v-for="field in record.fields" :key="field.key"><span>{{ field.label }}</span><strong>{{ field.value || '—' }}</strong></div></div></section>
        <section class="assessment-card"><div class="assessment-section-head"><h3>个人总结</h3><button v-if="canEdit && !editMode" type="button" :disabled="!canWrite" @click="editMode = true">编辑总结</button></div>
            <template v-if="editMode && canEdit"><div class="assessment-toolbar"><button type="button" @click="editor?.chain().focus().toggleBold().run()">加粗</button><button type="button" @click="editor?.chain().focus().toggleItalic().run()">斜体</button><button type="button" @click="editor?.chain().focus().toggleHeading({ level: 2 }).run()">标题</button><button type="button" @click="editor?.chain().focus().toggleBulletList().run()">项目列表</button><button type="button" @click="editor?.chain().focus().toggleOrderedList().run()">编号列表</button></div><EditorContent :editor="editor" class="assessment-editor" /><p v-if="errors.summary" class="assessment-error">{{ errors.summary }}</p><div class="assessment-form-actions"><button type="button" @click="editMode = false">取消</button><button type="button" class="primary" :disabled="busy || !canWrite" @click="save"><Save :size="16" />保存总结</button></div></template>
            <div v-else class="assessment-summary" v-html="record.summary || '<p>尚未填写个人总结。</p>'" />
        </section>
        <section v-if="canEdit || canWithdraw" class="assessment-card assessment-submit"><h3>提交部门审核</h3><p>请确认个人总结无误后提交。部门处理前可撤销提交。</p><p v-if="errors.summary" class="assessment-error">{{ errors.summary }}</p><button v-if="canEdit" type="button" class="primary" :disabled="busy || !canWrite || editMode" @click="submit"><Send :size="16" />提交年度考核</button><button v-if="canWithdraw" type="button" :disabled="busy || !canWrite" @click="withdraw"><Undo2 :size="16" />撤销提交</button></section>
        <section v-if="canReview" class="assessment-card assessment-review"><h3>部门审核</h3><label for="assessment-result">部门考核结果</label><select id="assessment-result" v-model="result"><option value="">请选择考核等次</option><option v-for="option in resultOptions" :key="option.code" :value="option.code">{{ option.label }}</option></select><p v-if="errors.result" class="assessment-error">{{ errors.result }}</p><button type="button" class="primary" :disabled="busy || !canWrite || !result" @click="approve">评定并完成审核</button><label for="assessment-note">退回原因</label><textarea id="assessment-note" v-model="note" rows="3" maxlength="2000" placeholder="说明需要补充或修改的内容" /><p v-if="errors.note" class="assessment-error">{{ errors.note }}</p><button type="button" class="danger" :disabled="busy || !canWrite || !note.trim()" @click="returnForChanges">退回员工修改</button></section>
        <section v-if="events.length" class="assessment-card"><h3>处理记录</h3><ol class="assessment-events"><li v-for="(event, index) in events" :key="index"><strong>{{ eventNames[event.action] || event.action }}</strong><span>{{ event.created_at }}</span><p v-if="event.note">{{ event.note }}</p><p v-if="event.result_code">考核等次：{{ resultOptions.find((option) => option.code === event.result_code)?.label || event.result_code }}</p></li></ol></section>
    </PersonnelShell>
</template>
