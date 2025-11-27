<?php

class User {
    private $pdo;
    private $id;
    private $username;
    private $email;
    private $password;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function getId() { return $this->id; }
    public function getUsername() { return $this->username; }
    public function getEmail() { return $this->email; }
    public function getPassword() { return $this->password; }

    public function setUsername($username) { $this->username = $username; }
    public function setEmail($email) { $this->email = $email; }
    public function setPassword($password) { $this->password = $password; }

    public function registerUser($username, $email, $password): bool {


        if (empty($username)) return false;
        if (strlen($password) < 9) return false;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;


        $check = $this->pdo->prepare("SELECT id FROM users WHERE username = :username OR email = :email");
        $check->execute([
            ':username' => $username,
            ':email' => $email
        ]);

        if ($check->rowCount() > 0) return false;


        $stmt = $this->pdo->prepare("
            INSERT INTO users (username, email, password)
            VALUES (:username, :email, :password)
        ");

        return $stmt->execute([
            ':username' => $username,
            ':email' => $email,
            ':password' => password_hash($password, PASSWORD_DEFAULT)
        ]);
    }


    public function authenticateUser($username, $password): bool {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE username = :username LIMIT 1");
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) return false;

        if (!password_verify($password, $user['password'])) return false;


        $this->id = $user['id'];
        $this->username = $user['username'];
        $this->email = $user['email'];
        $this->password = $user['password'];

        return true;
    }
}


class Topic {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }


    public function createTopic($userId, $title, $description): bool {
        $stmt = $this->pdo->prepare("
            INSERT INTO topics (user_id, title, description)
            VALUES (:uid, :title, :des)
        ");
        return $stmt->execute([
            ':uid' => $userId,
            ':title' => $title,
            ':des' => $description
        ]);
    }


    public function getTopics(): array {
        $stmt = $this->pdo->query("SELECT * FROM topics ORDER BY created_at DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    public function getCreatedTopics($userId): array {
        $stmt = $this->pdo->prepare("SELECT * FROM topics WHERE user_id = :uid ORDER BY created_at DESC");
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

class Vote {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }


    public function hasVoted($topicId, $userId): bool {
        $stmt = $this->pdo->prepare("
            SELECT id FROM votes WHERE topic_id = :tid AND user_id = :uid LIMIT 1
        ");
        $stmt->execute([
            ':tid' => $topicId,
            ':uid' => $userId
        ]);

        return $stmt->rowCount() > 0;
    }


    public function vote($userId, $topicId, $voteType): bool {

        if ($this->hasVoted($topicId, $userId)) return false;

        $stmt = $this->pdo->prepare("
            INSERT INTO votes (user_id, topic_id, vote_type)
            VALUES (:uid, :tid, :vtype)
        ");

        return $stmt->execute([
            ':uid'  => $userId,
            ':tid'  => $topicId,
            ':vtype'=> $voteType
        ]);
    }


    public function getUserVoteHistory($userId): array {
        $stmt = $this->pdo->prepare("
            SELECT topic_id, vote_type, voted_at
            FROM votes
            WHERE user_id = :uid
            ORDER BY voted_at DESC
        ");
        $stmt->execute([':uid' => $userId]);

        $votes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($votes as &$vote) {
            $vote['voted_at'] = TimeFormatter::formatTimestamp(strtotime($vote['voted_at']));
        }

        return $votes;
    }
}


class Comment {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }


    public function addComment($userId, $topicId, $comment): bool {
        $stmt = $this->pdo->prepare("
            INSERT INTO comments (user_id, topic_id, comment)
            VALUES (:uid, :tid, :content)
        ");

        return $stmt->execute([
            ':uid'     => $userId,
            ':tid'     => $topicId,
            ':content' => $comment
        ]);
    }


    public function getComments($topicId): array {
        $stmt = $this->pdo->prepare("
            SELECT user_id, comment, commented_at
            FROM comments
            WHERE topic_id = :tid
            ORDER BY commented_at DESC
        ");
        $stmt->execute([':tid' => $topicId]);

        $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($comments as &$c) {
            $c['commented_at'] = TimeFormatter::formatTimestamp(strtotime($c['commented_at']));
        }

        return $comments;
    }
}


class TimeFormatter {

    public static function formatTimestamp(int $timestamp): string {

        $now = time();
        $diff = $now - $timestamp;


        if ($diff < 60) {
            return $diff . " seconds ago";
        }


        if ($diff < 3600) {
            return floor($diff / 60) . " minutes ago";
        }


        if ($diff < 86400) {
            return floor($diff / 3600) . " hours ago";
        }


        if ($diff < 2592000) {
            return floor($diff / 86400) . " days ago";
        }


        if ($diff < 31536000) {
            return floor($diff / 2592000) . " months ago";
        }

        return date("M d, Y", $timestamp);
    }
}
?>