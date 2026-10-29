<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, Check, FilePenLine, Paperclip } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import PersonnelShell from '@/components/personnel/PersonnelShell.vue';

type Module = { menu_id: number; table: string; title: string };
type Field = { label: string; section: string; evidenceRequired: boolean; isDate: boolean; dictionary: boolean };
type Option = { value: string; label: string };
const props = defineProps<{
    modules: Record<string, Module>;
    fields: Record<string, Field>;
    sections: { key: string; title: string; fields: string[] }[];
    values: Record<string, string | null>;
    displayValues: Record<string, string | null>;
    options: Record<string, Option[]>;
    hasActiveRequest: boolean;
    readOnlyPreview: boolean;
}>();
const section = ref(props.sections[0]?.key ?? 'basic');
const edited = reactive<Record<string, string>>(Object.fromEntries(Object.entries(props.values).map(([key, value]) => [key, value ?? ''])));
const search = reactive<Record<string, string>>({});
const form = useForm<{ reason: string; changes: Record<string, string | null>; evidence: File[] }>({ reason: '', changes: {}, evidence: [] });
const activeSection = computed(() => props.sections.find((item) => item.key === section.value));
const changes = computed(() => Object.keys(props.fields).filter((key) => edited[key]?.trim() !== (props.values[key] ?? '').trim()));
const needsEvidence = computed(() => changes.value.some((key) => props.fields[key].evidenceRequired));
const display = (key: string, raw: string | null) => props.options[key]?.find((item) => item.value === raw)?.label ?? (raw || '未填写');
const legacyOption = (key: string) => !!edited[key] && !(props.options[key] ?? []).some((item) => item.value === edited[key]);
const filteredOptions = (key: string) => {
    const term = (search[key] ?? '').trim().toLowerCase();
    const all = props.options[key] ?? [];
    const selected = all.find((item) => item.value === edited[key]);
    const listed = all.filter((item) => !term || item.label.toLowerCase().includes(term) || item.value.toLowerCase().includes(term)).slice(0, 80);
    return selected && !listed.some((item) => item.value === selected.value) ? [selected, ...listed] : listed;
};
const chooseFiles = (event: Event) => { form.evidence = Array.from((event.target as HTMLInputElement).files ?? []); };
const submit = () => {
    form.changes = Object.fromEntries(changes.value.map((key) => [key, edited[key]?.trim() || null]));
    form.post('/personnel/corrections', { forceFormData: true, preserveScroll: true });
};
</script>

<template>
    <Head title="申请档案更正" />
    <PersonnelShell :modules="modules" title="申请档案更正" eyebrow="PERSONNEL FILE / INFORMATION CORRECTION" active-key="corrections">
        <div class="correction-intro"><FilePenLine :size="20" /><div><strong>更正申请</strong><p>选择需要更正的字段。申请提交后，只有人力资源部审核通过并成功写回，个人档案才会更新。</p></div></div>
        <div v-if="hasActiveRequest" class="correction-warning">您已有待处理的更正申请。请先查看状态或撤回，再提交新申请。<Link href="/personnel/corrections">查看申请</Link></div>
        <div v-if="readOnlyPreview" class="correction-warning">本机预览仅用于核对页面与数据，不能提交更正申请。</div>
        <nav class="correction-tabs" aria-label="更正字段类别"><button v-for="item in sections" :key="item.key" type="button" :class="{ active: section === item.key }" @click="section = item.key">{{ item.title }}<span>{{ item.fields.filter((key) => changes.includes(key)).length || '' }}</span></button></nav>
        <form class="correction-form" @submit.prevent="submit">
            <section class="correction-panel"><header><div><span>01 / SELECT FIELDS</span><h2>{{ activeSection?.title }}</h2></div><small>{{ changes.length }} 项已修改</small></header>
                <div class="correction-field-grid">
                    <div v-for="key in activeSection?.fields" :key="key" :class="['correction-field', { modified: changes.includes(key) }]">
                        <div class="correction-field-top"><label :for="`correction-${key}`">{{ fields[key].label }}</label><span v-if="changes.includes(key)"><Check :size="12" /> 已修改</span></div>
                        <p class="correction-old">当前：{{ displayValues[key] || '未填写' }}</p>
                        <template v-if="fields[key].dictionary">
                            <input v-if="(options[key]?.length ?? 0) > 80" v-model="search[key]" class="correction-search" type="search" :aria-label="`搜索${fields[key].label}`" placeholder="搜索字典选项" />
                            <select :id="`correction-${key}`" v-model="edited[key]" class="correction-input"><option value="">未填写</option><option v-if="legacyOption(key)" :value="edited[key]">{{ displayValues[key] || edited[key] }}（原档案值）</option><option v-for="option in filteredOptions(key)" :key="option.value" :value="option.value">{{ option.label }}</option></select>
                        </template>
                        <textarea v-else-if="key === 'h5076'" :id="`correction-${key}`" v-model="edited[key]" class="correction-input" rows="3" placeholder="填写更正内容" />
                        <input v-else :id="`correction-${key}`" v-model="edited[key]" class="correction-input" :type="fields[key].isDate ? 'date' : key === 'h5087' ? 'email' : 'text'" :maxlength="key === 'h5065' ? 1 : key === 'h5001' ? 50 : 255" placeholder="填写更正内容" />
                        <p v-if="form.errors[`changes.${key}`]" class="correction-error">{{ form.errors[`changes.${key}`] }}</p>
                    </div>
                </div>
            </section>
            <section class="correction-panel"><header><div><span>02 / REVIEW CHANGES</span><h2>核对申请内容</h2></div></header>
                <div v-if="changes.length" class="correction-diff-list"><div v-for="key in changes" :key="key"><strong>{{ fields[key].label }}</strong><span>{{ display(key, values[key]) }}</span><ArrowRight :size="15" /><b>{{ display(key, edited[key]) }}</b></div></div>
                <p v-else class="correction-empty">尚未修改字段。请在上方选择类别并编辑。</p>
            </section>
            <section class="correction-panel correction-submit-panel"><header><div><span>03 / SUBMIT</span><h2>说明与证明材料</h2></div></header>
                <label class="correction-label" for="correction-reason">更正说明 <span>必填</span></label><textarea id="correction-reason" v-model="form.reason" class="correction-input" rows="3" maxlength="2000" placeholder="说明更正原因及依据" /><p v-if="form.errors.reason" class="correction-error">{{ form.errors.reason }}</p>
                <label class="correction-label" for="correction-evidence"><Paperclip :size="15" />证明材料 <span>{{ needsEvidence ? '当前修改项必传' : '可选' }}</span></label><input id="correction-evidence" type="file" accept=".pdf,.jpg,.jpeg,.png" multiple @change="chooseFiles" /><small>PDF、JPG 或 PNG；最多 5 份，每份不超过 10 MB。材料仅本人和审核人可查看。</small><p v-if="form.errors.evidence" class="correction-error">{{ form.errors.evidence }}</p>
                <p v-if="form.errors.changes" class="correction-error">{{ form.errors.changes }}</p>
                <div class="correction-submit-actions"><Link href="/personnel/corrections"><ArrowLeft :size="15" />返回申请列表</Link><button type="submit" :disabled="readOnlyPreview || hasActiveRequest || !changes.length || form.processing">{{ form.processing ? '提交中…' : '提交审核' }}</button></div>
            </section>
        </form>
    </PersonnelShell>
</template>
