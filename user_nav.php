<!-- 
User Navigation Component
Include this in your HTML pages to show logged-in user info and logout button
-->

<style>
    .user-nav {
        position: fixed;
        top: 0;
        right: 0;
        padding: 16px 24px;
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        border-bottom-left-radius: 8px;
        display: flex;
        align-items: center;
        gap: 16px;
        z-index: 1000;
    }

    .user-info {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .user-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        object-fit: cover;
    }

    .user-avatar-placeholder {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 600;
        font-size: 16px;
    }

    .user-details {
        display: flex;
        flex-direction: column;
    }

    .user-name {
        font-weight: 600;
        color: #1a202c;
        font-size: 14px;
    }

    .user-email {
        font-size: 12px;
        color: #718096;
    }

    .logout-btn {
        padding: 8px 16px;
        background: #f7fafc;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        color: #2d3748;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        text-decoration: none;
        display: inline-block;
    }

    .logout-btn:hover {
        background: #edf2f7;
        border-color: #cbd5e0;
    }

    @media (max-width: 640px) {
        .user-nav {
            padding: 12px 16px;
        }

        .user-details {
            display: none;
        }
    }
</style>

<div class="user-nav">
    <div class="user-info">
        <?php if (!empty($user_picture)): ?>
            <img src="<?php echo htmlspecialchars($user_picture); ?>" alt="User" class="user-avatar">
        <?php else: ?>
            <div class="user-avatar-placeholder">
                <?php echo strtoupper(substr($user_name ?: $current_user, 0, 1)); ?>
            </div>
        <?php endif; ?>
        
        <div class="user-details">
            <?php if (!empty($user_name)): ?>
                <div class="user-name"><?php echo htmlspecialchars($user_name); ?></div>
            <?php endif; ?>
            <div class="user-email"><?php echo htmlspecialchars($current_user); ?></div>
        </div>
    </div>
    
    <a href="logout.php" class="logout-btn">Logout</a>
</div>
