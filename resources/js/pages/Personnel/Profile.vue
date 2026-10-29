<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Download, FilePenLine, Info, Printer, UserRound } from '@lucide/vue';
import { computed, ref } from 'vue';
import PersonnelShell from '@/components/personnel/PersonnelShell.vue';

type Module = { menu_id: number; table: string; title: string };
type Section = { key: string; title: string; description: string; count: number };
type Field = { key: string; label: string };

const props = defineProps<{
    modules: Record<string, Module>;
    sections: Section[];
    activeSection: string;
    fields: Field[];
    record: Record<string, string | null> | null;
    identity: Record<string, string | null> | null;
    hasPortrait: boolean;
    canRequestCorrection: boolean;
}>();

const active = computed(() => props.sections.find((section) => section.key === props.activeSection));
const activeIndex = computed(() => props.sections.findIndex((section) => section.key === props.activeSection) + 1);
const valueFor = (key: string) => props.record?.[key] ?? null;
const isEmpty = (key: string) => !valueFor(key)?.trim();
const portraitFailed = ref(false);
</script>

<template>
    <Head title="个人信息" />
    <PersonnelShell :modules="modules" title="个人信息" eyebrow="PERSONNEL FILE / 个人档案">
        <template v-if="record">
            <header class="profile-identity">
                <div class="profile-portrait">
                    <img v-if="hasPortrait && !portraitFailed" src="/personnel/profile/portrait" alt="本人档案照片" @error="portraitFailed = true" />
                    <UserRound v-else :size="30" aria-label="暂无照片" />
                </div>
                <div class="profile-identity-copy">
                    <span class="personnel-eyebrow">PERSONNEL FILE / 个人档案</span>
                    <h2>{{ identity?.h5001 || '未填写姓名' }}</h2>
                    <p><span class="profile-number">{{ identity?.h5002 || '未填写编号' }}</span><i aria-hidden="true"></i><span class="profile-department">{{ identity?.h5003 || '未填写部门' }}</span></p>
                </div>
                <div class="profile-actions">
                    <Link v-if="canRequestCorrection" href="/personnel/corrections/new" class="profile-action"><FilePenLine :size="15" />申请更正</Link>
                    <a href="/personnel/profile/pdf" class="profile-action primary"><Download :size="15" />导出 PDF</a>
                    <a href="/personnel/profile/print" class="profile-action" target="_blank" rel="noopener"><Printer :size="15" />打印</a>
                </div>
            </header>

            <nav class="profile-section-nav" aria-label="个人信息类别">
                <Link
                    v-for="(section, index) in sections"
                    :key="section.key"
                    :href="`/personnel/profile?section=${section.key}`"
                    class="profile-section-link"
                    :class="{ active: section.key === activeSection }"
                    :aria-current="section.key === activeSection ? 'page' : undefined"
                >
                    <span class="profile-section-index">{{ String(index + 1).padStart(2, '0') }}</span>
                    <span>{{ section.title }}</span>
                    <small>{{ section.count }}</small>
                </Link>
            </nav>

            <section class="profile-section-panel">
                <header class="profile-section-heading">
                    <div>
                        <span class="personnel-eyebrow">{{ activeSection.toUpperCase() }} / {{ activeIndex }} OF {{ sections.length }}</span>
                        <h2>{{ active?.title }}</h2>
                        <p>{{ active?.description }}</p>
                    </div>
                    <span class="profile-section-total">{{ fields.length }} 项资料</span>
                </header>
                <dl class="profile-field-grid">
                    <div v-for="field in fields" :key="field.key" class="profile-field">
                        <dt class="profile-field-label">{{ field.label }}</dt>
                        <dd class="profile-field-value" :class="{ empty: isEmpty(field.key) }">{{ isEmpty(field.key) ? '未填写' : valueFor(field.key) }}</dd>
                    </div>
                </dl>
                <footer class="profile-section-footer"><span>资料来源：现有人事系统</span><span>只读预览</span></footer>
            </section>
        </template>
        <div v-else class="personnel-empty"><Info :size="27" /><h2>未找到对应档案</h2><p>当前门户账号没有匹配的职工编号，请联系系统管理员核对。</p></div>
    </PersonnelShell>
</template>
