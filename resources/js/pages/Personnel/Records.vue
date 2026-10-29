<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { Search, SlidersHorizontal } from '@lucide/vue';
import PersonnelShell from '@/components/personnel/PersonnelShell.vue';

type Module = { menu_id: number; table: string; title: string };
const props = defineProps<{
    modules: Record<string, Module>;
    title: string;
    subtitle: string;
    fields: { key: string; label: string }[];
    records: Record<string, unknown>[];
    kind: string;
}>();
const query = ref('');
const filtered = computed(() => props.records.filter((record) => !query.value || props.fields.some((field) => String(record[field.key] ?? '').includes(query.value))));
</script>

<template>
    <Head :title="title" />
    <PersonnelShell :modules="modules" :title="title" :eyebrow="kind === 'leave' ? 'LEAVE / 个人申请' : 'ASSESSMENT / 历年记录'">
        <div class="personnel-intro"><SlidersHorizontal :size="23" /><div><strong>{{ subtitle }}</strong><p>记录及原始状态由现有业务库实时提供。</p></div></div>
        <section class="personnel-table-card">
            <div class="personnel-table-toolbar"><div><span>记录列表</span><strong>{{ filtered.length }} <small>条</small></strong></div><label class="personnel-search"><Search :size="17" /><input v-model="query" type="search" placeholder="搜索当前记录" aria-label="搜索当前记录"></label></div>
            <div class="personnel-table-scroll"><table><thead><tr><th v-for="field in fields" :key="field.key">{{ field.label }}</th></tr></thead><tbody><tr v-for="record in filtered" :key="String(record.Id)"><td v-for="field in fields" :key="field.key">{{ record[field.key] || '—' }}</td></tr><tr v-if="filtered.length === 0"><td :colspan="fields.length" class="personnel-table-empty">暂无符合条件的记录</td></tr></tbody></table></div>
        </section>
    </PersonnelShell>
</template>
