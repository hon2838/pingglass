<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Badge from '@/Components/ui/badge/Badge.vue';
import Button from '@/Components/ui/button/Button.vue';
import Card from '@/Components/ui/card/Card.vue';
import CardContent from '@/Components/ui/card/CardContent.vue';
import CardDescription from '@/Components/ui/card/CardDescription.vue';
import CardHeader from '@/Components/ui/card/CardHeader.vue';
import CardTitle from '@/Components/ui/card/CardTitle.vue';
import FlashMessages from '@/Components/FlashMessages.vue';
import Input from '@/Components/ui/input/Input.vue';
import Label from '@/Components/ui/label/Label.vue';
import Table from '@/Components/ui/table/Table.vue';
import TableBody from '@/Components/ui/table/TableBody.vue';
import TableCell from '@/Components/ui/table/TableCell.vue';
import TableHead from '@/Components/ui/table/TableHead.vue';
import TableHeader from '@/Components/ui/table/TableHeader.vue';
import TableRow from '@/Components/ui/table/TableRow.vue';
import { formatDateTime } from '@/lib/utils';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    users: Array,
});

const createForm = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const selectedUser = ref(null);
const passwordForm = useForm({
    password: '',
    password_confirmation: '',
});

function createUser() {
    createForm.post(route('admin.users.store'), {
        preserveScroll: true,
        onSuccess: () => createForm.reset(),
        onFinish: () => {
            createForm.password = '';
            createForm.password_confirmation = '';
        },
    });
}

function selectUser(user) {
    selectedUser.value = user;
    passwordForm.clearErrors();
    passwordForm.reset();
}

function updatePassword() {
    if (!selectedUser.value) return;

    passwordForm.put(route('admin.users.password.update', selectedUser.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            passwordForm.reset();
            selectedUser.value = null;
        },
        onFinish: () => {
            passwordForm.password = '';
            passwordForm.password_confirmation = '';
        },
    });
}
</script>

<template>
    <div>
        <div class="mb-6">
            <h1 class="text-2xl font-bold">Administrators</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Every account listed here can access and manage the complete PingGlass admin panel.
            </p>
        </div>

        <FlashMessages class="mb-6" />

        <div class="grid gap-6 xl:grid-cols-[minmax(0,420px)_minmax(0,1fr)]">
            <div class="space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Add administrator</CardTitle>
                        <CardDescription>Create a separate account for each person who manages PingGlass.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form class="space-y-4" @submit.prevent="createUser">
                            <div class="space-y-2">
                                <Label for="new-user-name">Name</Label>
                                <Input id="new-user-name" v-model="createForm.name" autocomplete="name" :disabled="createForm.processing" />
                                <p v-if="createForm.errors.name" class="text-sm text-destructive">{{ createForm.errors.name }}</p>
                            </div>

                            <div class="space-y-2">
                                <Label for="new-user-email">Email</Label>
                                <Input id="new-user-email" v-model="createForm.email" type="email" autocomplete="email" placeholder="name@example.com" :disabled="createForm.processing" />
                                <p v-if="createForm.errors.email" class="text-sm text-destructive">{{ createForm.errors.email }}</p>
                            </div>

                            <div class="space-y-2">
                                <Label for="new-user-password">Password</Label>
                                <Input id="new-user-password" v-model="createForm.password" type="password" autocomplete="new-password" :disabled="createForm.processing" />
                                <p class="text-xs text-muted-foreground">At least 12 characters with upper/lowercase letters and a number.</p>
                                <p v-if="createForm.errors.password" class="text-sm text-destructive">{{ createForm.errors.password }}</p>
                            </div>

                            <div class="space-y-2">
                                <Label for="new-user-password-confirmation">Confirm password</Label>
                                <Input id="new-user-password-confirmation" v-model="createForm.password_confirmation" type="password" autocomplete="new-password" :disabled="createForm.processing" />
                            </div>

                            <Button type="submit" class="w-full" :disabled="createForm.processing">
                                {{ createForm.processing ? 'Creating...' : 'Create administrator' }}
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                <Card v-if="selectedUser">
                    <CardHeader>
                        <CardTitle>Change password</CardTitle>
                        <CardDescription>
                            Set a new password for {{ selectedUser.name }} ({{ selectedUser.email }}).
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form class="space-y-4" @submit.prevent="updatePassword">
                            <div class="space-y-2">
                                <Label for="changed-password">New password</Label>
                                <Input id="changed-password" v-model="passwordForm.password" type="password" autocomplete="new-password" :disabled="passwordForm.processing" />
                                <p class="text-xs text-muted-foreground">At least 12 characters with upper/lowercase letters and a number.</p>
                                <p v-if="passwordForm.errors.password" class="text-sm text-destructive">{{ passwordForm.errors.password }}</p>
                            </div>

                            <div class="space-y-2">
                                <Label for="changed-password-confirmation">Confirm new password</Label>
                                <Input id="changed-password-confirmation" v-model="passwordForm.password_confirmation" type="password" autocomplete="new-password" :disabled="passwordForm.processing" />
                            </div>

                            <div class="flex gap-2">
                                <Button type="submit" :disabled="passwordForm.processing">
                                    {{ passwordForm.processing ? 'Updating...' : 'Update password' }}
                                </Button>
                                <Button type="button" variant="outline" :disabled="passwordForm.processing" @click="selectedUser = null; passwordForm.reset()">
                                    Cancel
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Administrator accounts</CardTitle>
                    <CardDescription>{{ users.length }} account{{ users.length === 1 ? '' : 's' }} can access this panel.</CardDescription>
                </CardHeader>
                <CardContent class="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Email</TableHead>
                                <TableHead>Created</TableHead>
                                <TableHead class="text-right">Action</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="user in users" :key="user.id">
                                <TableCell class="font-medium">
                                    <div class="flex items-center gap-2">
                                        <span>{{ user.name }}</span>
                                        <Badge v-if="user.is_current" variant="secondary">You</Badge>
                                    </div>
                                </TableCell>
                                <TableCell>{{ user.email }}</TableCell>
                                <TableCell class="text-sm text-muted-foreground">{{ formatDateTime(user.created_at) }}</TableCell>
                                <TableCell class="text-right">
                                    <Button type="button" size="sm" variant="outline" @click="selectUser(user)">
                                        Change password
                                    </Button>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
