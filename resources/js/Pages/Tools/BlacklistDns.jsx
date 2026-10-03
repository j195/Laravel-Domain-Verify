import { Head } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import CheckWorkbench from '../../Components/CheckWorkbench';

export default function BlacklistDns({ batch, jobs }) {
    return (
        <AppLayout title="Blacklist + DNS Checker">
            <Head title="Blacklist + DNS" />
            <CheckWorkbench
                type="blacklist"
                title="Blacklist + DNS"
                subtitle="Enter a domain or email. For emails, the domain is extracted automatically. Bulk jobs update the table as each lookup completes."
                initialBatch={batch}
                jobs={jobs}
                extraFields
            />
        </AppLayout>
    );
}
