USE gym_management;

SET NAMES utf8mb4;
SET time_zone = '+08:00';

CREATE TABLE member_chat_conversations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id INT(11) NOT NULL,
    title VARCHAR(120) NOT NULL DEFAULT 'New conversation',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_member_chat_conversation_member_updated (member_id, updated_at, id),
    CONSTRAINT fk_member_chat_conversation_member
        FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE member_chat_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id BIGINT UNSIGNED NOT NULL,
    role ENUM('user', 'assistant') NOT NULL,
    content MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_member_chat_message_conversation_role_created (conversation_id, role, created_at, id),
    CONSTRAINT fk_member_chat_message_conversation
        FOREIGN KEY (conversation_id) REFERENCES member_chat_conversations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
