<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ChevronDown, ChevronLeft, ChevronRight, FileImage, History as HistoryIcon } from '@lucide/vue';
import { ref, watch } from 'vue';
import PersonnelShell from '@/components/personnel/PersonnelShell.vue';

type Module = { menu_id: number; table: string; title: string };
type Field = { key: string; label: string; value: string | null; changed: boolean; certificate: boolean; hasReference: boolean };
type RecordItem = { id: number; date: string | null; summary: string[]; changedCount: number; baseline: boolean; groups: { title: string; fields: Field[] }[] };
const props = defineProps<{
    modules: Record<string, Module>;
    tabs: { key: string; title: string }[];
    activeTab: string;
    history: { records: RecordItem[]; total: number; page: number; lastPage: number };
}>();
const expanded = ref<number[]>([]);
const failedImages = ref<string[]>([]);
watch(() => [props.activeTab, props.history.page], () => { expanded.value = []; failedImages.value = []; });
const toggle = (id: number) => { expanded.value = expanded.value.includes(id) ? expanded.value.filter((item) => item !== id) : [...expanded.value, id]; };
const fileKey = (id: number, field: string) => `${id}:${field}`;
const fileUrl = (id: number, field: string) => `/personnel/history/certificate?tab=${props.activeTab}&id=${id}&field=${field}`;
const markFailed = (id: number, field: string) => { failedImages.value.push(fileKey(id, field)); };
const pageUrl = (page: number) => `/personnel/history?tab=${props.activeTab}&page=${page}`;
</script>

<template>
    <Head title="档案履历" />
    <PersonnelShell :modules="modules" title="档案履历" eyebrow="PERSONNEL HISTORY / 本人履历">
        <section class="history-intro">
            <HistoryIcon :size="20" />
            <p>按业务日期展示本人的历史记录。<strong>“变化”仅表示与时间上前一条业务记录的字段差异，并非数据库编辑日志。</strong></p>
        </section>
        <nav class="history-tabs" aria-label="档案履历类别">
            <Link v-for="tab in tabs" :key="tab.key" :href="`/personnel/history?tab=${tab.key}`" :class="['history-tab', { active: tab.key === activeTab }]" :aria-current="tab.key === activeTab ? 'page' : undefined">{{ tab.title }}</Link>
        </nav>
        <div class="history-toolbar"><span>共 {{ history.total }} 条记录</span><small>最新在前 · 每页 20 条</small></div>
        <div v-if="history.records.length === 0" class="history-empty">此类别暂无记录</div>
        <div v-else class="history-list">
            <article v-for="record in history.records" :key="record.id" class="history-record">
                <div class="history-record-head">
                    <div class="history-record-date"><small>业务日期</small><strong>{{ record.date || '未填写' }}</strong></div>
                    <div class="history-record-main">
                        <h2>{{ record.summary.length ? record.summary.join(' · ') : '未填写关键内容' }}</h2>
                        <p v-if="record.baseline" class="history-baseline">最早记录 · 比较基线</p>
                        <p v-else-if="record.changedCount" class="history-change-count">与前一条记录有 {{ record.changedCount }} 项字段不同</p>
                        <p v-else class="history-no-change">与前一条记录的业务字段相同</p>
                    </div>
                    <button type="button" class="history-expand" :aria-expanded="expanded.includes(record.id)" :aria-controls="`history-detail-${record.id}`" @click="toggle(record.id)">
                        {{ expanded.includes(record.id) ? '收起' : '查看完整字段' }} <ChevronDown :size="16" :class="{ rotated: expanded.includes(record.id) }" />
                    </button>
                </div>
                <div v-if="expanded.includes(record.id)" :id="`history-detail-${record.id}`" class="history-groups">
                    <section v-for="group in record.groups" :key="group.title" class="history-group">
                        <h3>{{ group.title }}</h3>
                        <dl class="history-fields">
                            <div v-for="field in group.fields" :key="field.key" :class="['history-field', { changed: field.changed }]">
                                <dt>{{ field.label }}<span v-if="field.changed" class="history-changed-label">较前条不同</span></dt>
                                <dd v-if="!field.certificate" :class="{ empty: !field.value }">{{ field.value || '未填写' }}</dd>
                                <dd v-else-if="!field.hasReference" class="empty">未上传</dd>
                                <dd v-else-if="failedImages.includes(fileKey(record.id, field.key))" class="empty">附件不可用</dd>
                                <dd v-else class="history-certificate"><img :src="fileUrl(record.id, field.key)" :alt="field.label" loading="lazy" @error="markFailed(record.id, field.key)" /><FileImage :size="14" />证书图片</dd>
                            </div>
                        </dl>
                    </section>
                </div>
            </article>
        </div>
        <nav v-if="history.lastPage > 1" class="history-pagination" aria-label="履历分页">
            <Link v-if="history.page > 1" :href="pageUrl(history.page - 1)"><ChevronLeft :size="16" />上一页</Link><span v-else class="disabled">上一页</span>
            <span>第 {{ history.page }} / {{ history.lastPage }} 页</span>
            <Link v-if="history.page < history.lastPage" :href="pageUrl(history.page + 1)">下一页<ChevronRight :size="16" /></Link><span v-else class="disabled">下一页</span>
        </nav>
    </PersonnelShell>
</template>
