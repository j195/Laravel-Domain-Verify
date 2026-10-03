import { Head } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import CheckWorkbench from '../../Components/CheckWorkbench';

export default function ProviderDetect({ batch, jobs }) {
    return (
        <AppLayout title="Google Workspace / Microsoft 365 Detection">
            <Head title="Google Workspace / Microsoft 365" />
            <CheckWorkbench
                type="provider"
                title="Google Workspace / Microsoft 365"
                subtitle="Each result shows Domain, Provider (Google Workspace / Microsoft 365 / Other / Not Detected), MX records found, detection evidence, and Status (Active/Detected or Not Detected)."
                initialBatch={batch}
                jobs={jobs}
            />
        </AppLayout>
    );
}
