<?php if ( ! is_user_logged_in() ) : ?>
<p>Please log in to view your dashboard.</p>
<?php else : ?>
<div class="ddv ddv-dashboard">

    <h2>Dashboard</h2>
    <p>Your central hub for compliance, documents, and activity.</p>

    <div class="ddv-grid">

        <div class="ddv-card">
            <h3>Compliance Overview</h3>
            <p>Monitor accreditation progress and upcoming requirements.</p>
            <ul>
                <li>Completed: <strong>0</strong></li>
                <li>Pending: <strong>0</strong></li>
                <li>Next Action: <strong>None</strong></li>
            </ul>
            <button class="ddv-button ddv-secondary">View Requirements</button>
        </div>

        <div class="ddv-card">
            <h3>Recent Documents</h3>
            <p>Your latest uploads will appear here.</p>
            <ul>
                <li>No recent documents.</li>
            </ul>
            <button class="ddv-button ddv-secondary">Open Vault</button>
        </div>

        <div class="ddv-card">
            <h3>Quick Actions</h3>
            <button class="ddv-button ddv-primary">Upload Document</button>
            <button class="ddv-button ddv-secondary">Sort Documents</button>
            <button class="ddv-button ddv-secondary">Open Vault</button>
        </div>

        <div class="ddv-card">
            <h3>Upcoming Deadlines</h3>
            <p>No deadlines scheduled.</p>
            <button class="ddv-button ddv-secondary">View All Deadlines</button>
        </div>

    </div>

</div>

<?php endif; ?>