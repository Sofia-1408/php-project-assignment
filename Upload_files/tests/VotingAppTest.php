<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../classes.php';

class VotingAppTest extends TestCase {
    private static $pdo;
    private static $dbName;

    public static function setUpBeforeClass(): void {
        // Load database connection information
        $config = include __DIR__ . '/../db.config.php';
        self::$dbName = $config['test']['dbname'];
        
        try {
            self::$pdo = new PDO(
                "mysql:host={$config['test']['host']}",
                $config['test']['username'],
                $config['test']['password']
            );
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            // Assuming the database already exists on shared hosting. No CREATE DATABASE statement.
            self::$pdo->exec("USE " . self::$dbName);
    
            $tablesSqlPath = __DIR__ . '/../tables.sql';
            if (file_exists($tablesSqlPath)) {
                
            // Drop all existing tables before applying the schema
            $result = self::$pdo->query("SHOW TABLES");
            if ($result) {
                while ($row = $result->fetch(PDO::FETCH_NUM)) {
                    self::$pdo->exec("SET foreign_key_checks = 0;DROP TABLE IF EXISTS " . $row[0] ."; SET foreign_key_checks = 1;");
                }
            }

            $tablesSql = file_get_contents($tablesSqlPath);
                self::$pdo->exec($tablesSql);
            } else {
                throw new Exception("Schema file tables.sql is missing.");
            }
        } catch (Exception $e) {
            throw new Exception("Failed to set up the database: " . $e->getMessage());
        }
    }

    protected function setUp(): void {
        if (self::$pdo) {
            self::$pdo->exec("USE " . self::$dbName);
            // Clear all tables before each test
            self::$pdo->exec("DELETE FROM Comments");
            self::$pdo->exec("DELETE FROM Votes");
            self::$pdo->exec("DELETE FROM Topics");
            self::$pdo->exec("DELETE FROM Users");
        }
    }

    public function testRegisterUserWithValidData() {
        $user = new User(self::$pdo);
        $username = 'testuser';
        $email = 'testuser@example.com';
        $password = 'password123';
    
        $result = $user->registerUser($username, $email, $password);
        $this->assertTrue($result, "User registration failed with valid data");
    
        $stmt = self::$pdo->prepare("SELECT * FROM Users WHERE username = ?");
        $stmt->execute([$username]);
        $userRecord = $stmt->fetch(PDO::FETCH_ASSOC);
    
        $this->assertNotEmpty($userRecord, "User record not found in database");
        $this->assertEquals($email, $userRecord['email'], "Email mismatch in database");
        $this->assertTrue(password_verify($password, $userRecord['password']), "Password not properly hashed");
    }
    
    public function testRegisterDuplicateUser() {
        $user = new User(self::$pdo);
        $username = 'duplicateuser';
        $email = 'duplicate@example.com';
        $password = 'password123';
    
        // First registration should succeed
        $firstResult = $user->registerUser($username, $email, $password);
        $this->assertTrue($firstResult, "First user registration failed");
    
        // Attempt to register the same username
        $secondResult = $user->registerUser($username, 'different@example.com', 'differentpassword');
        $this->assertFalse($secondResult, "Duplicate username registration should fail");
    
        // Attempt to register the same email
        $thirdResult = $user->registerUser('differentuser', $email, 'differentpassword');
        $this->assertFalse($thirdResult, "Duplicate email registration should fail");
    }
    
    public function testRegisterUserWithInvalidData() {
        $user = new User(self::$pdo);
        
        // Test with empty username
        $result1 = $user->registerUser('', 'test@example.com', 'password123');
        $this->assertFalse($result1, "Registration should fail with empty username");
    
        // Test with invalid email format
        $result2 = $user->registerUser('testuser2', 'invalid-email', 'password123');
        $this->assertFalse($result2, "Registration should fail with invalid email format");
    
        // Test with very long username (assuming there's a reasonable limit)
        $password = str_repeat('a', 8); 
        $result3 = $user->registerUser('testuser2', 'test3@example.com', $password);
        $this->assertFalse($result3, "Registration should fail with password length < 9 characters");
    }
    // Enhanced Topic Tests
    public function testCreateTopicWithValidData() {
        $topic = new Topic(self::$pdo);
        $userId = $this->createTestUser();

        $title = 'Test Topic';
        $description = 'Test Description';

        $result = $topic->createTopic($userId, $title, $description);
        $this->assertTrue($result, "Topic creation failed with valid data");

        $stmt = self::$pdo->prepare("SELECT * FROM Topics WHERE user_id = ? AND title = ?");
        $stmt->execute([$userId, $title]);
        $topicRecord = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotEmpty($topicRecord, "Topic not found in database");
        $this->assertEquals($description, $topicRecord['description'], "Topic description mismatch");
    }

    public function testGetTopicsReturnsCorrectObjects() {
        $topic = new Topic(self::$pdo);
        $userId = $this->createTestUser();

        // Create multiple topics
        $topicsData = [
            ['Test Topic 1', 'Description 1'],
            ['Test Topic 2', 'Description 2'],
            ['Test Topic 3', 'Description 3']
        ];

        foreach ($topicsData as $data) {
            $topic->createTopic($userId, $data[0], $data[1]);
        }

        $topics = $topic->getTopics();
        
        $this->assertCount(3, $topics, "Incorrect number of topics returned");
        $this->assertInstanceOf(Topic::class, $topics[0], "Returned object is not a Topic instance");
        $this->assertEquals('Test Topic 1', $topics[0]->title, "Topic title mismatch");
    }

    public function testGetCreatedTopics() {
        $user = new User(self::$pdo);
        $topic = new Topic(self::$pdo);
    
        // Create a test user
        $username = 'testuser';
        $email = 'testuser@example.com';
        $password = 'password123';
        $user->registerUser($username, $email, $password);
        $userId = self::$pdo->lastInsertId();
    
        // Add topics created by the test user
        $topics = [
            ['title' => 'First Topic', 'description' => 'Description of the first topic.'],
            ['title' => 'Second Topic', 'description' => 'Description of the second topic.']
        ];
    
        foreach ($topics as $t) {
            $topic->createTopic($userId, $t['title'], $t['description']);
        }
    
        // Fetch topics created by the test user
        $createdTopics = $topic->getCreatedTopics($userId);
    
        // Verify the returned array
        $this->assertIsArray($createdTopics, "getCreatedTopics should return an array.");
        $this->assertCount(2, $createdTopics, "getCreatedTopics should return exactly 2 topics.");
    
        // Verify individual topic details
        foreach ($createdTopics as $index => $createdTopic) {
            $this->assertEquals($topics[$index]['title'], $createdTopic['title'], "Topic title mismatch.");
            $this->assertEquals($topics[$index]['description'], $createdTopic['description'], "Topic description mismatch.");
    
            // Verify the topic's userId in the database
            $stmt = self::$pdo->prepare("SELECT * FROM Topics WHERE id = ?");
            $stmt->execute([$createdTopic['id']]);
            $dbRecord = $stmt->fetch(PDO::FETCH_ASSOC);
    
            $this->assertNotEmpty($dbRecord, "No database record found for topic ID: {$createdTopic['id']}.");
            $this->assertEquals($userId, $dbRecord['user_id'], "Topic does not belong to the expected user.");
        }
    }
    
    

    // Enhanced Vote Tests
    public function testVoteWithValidData() {
        $vote = new Vote(self::$pdo);
        $userId = $this->createTestUser();
        $topicId = $this->createTestTopic($userId);

        $result = $vote->vote($userId, $topicId, 'up');
        $this->assertTrue($result, "Vote creation failed with valid data");

        $stmt = self::$pdo->prepare("SELECT * FROM Votes WHERE user_id = ? AND topic_id = ?");
        $stmt->execute([$userId, $topicId]);
        $voteRecord = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotEmpty($voteRecord, "Vote not found in database");
        $this->assertEquals('up', $voteRecord['vote_type'], "Vote type mismatch");
    }

    public function testHasVotedReturnsCorrectStatus() {
        $vote = new Vote(self::$pdo);
        $userId = $this->createTestUser();
        $topicId = $this->createTestTopic($userId);

        $this->assertFalse($vote->hasVoted($topicId,$userId), "hasVoted should return false before voting");

        $vote->vote($userId, $topicId, 'up');
        $this->assertTrue($vote->hasVoted($topicId,$userId), "hasVoted should return true after voting");
    }

    // Enhanced Comment Tests
    public function testAddCommentWithValidData() {
        $comment = new Comment(self::$pdo);
        $userId = $this->createTestUser();
        $topicId = $this->createTestTopic($userId);
        $commentText = "Test comment";

        $result = $comment->addComment($userId, $topicId, $commentText);
        $this->assertTrue($result, "Comment creation failed with valid data");

        $comments = $comment->getComments($topicId);
        $this->assertCount(1, $comments, "Incorrect number of comments returned");
        $this->assertEquals($commentText, $comments[0]['comment'], "Comment text mismatch");
    }

    public function testGetCommentsReturnsCorrectData() {
        $comment = new Comment(self::$pdo);
        $userId = $this->createTestUser();
        $topicId = $this->createTestTopic($userId);

        // Add multiple comments
        $commentTexts = ['Comment 1', 'Comment 2', 'Comment 3'];
        foreach ($commentTexts as $text) {
            $comment->addComment($userId, $topicId, $text);
        }

        $comments = $comment->getComments($topicId);
        $this->assertCount(3, $comments, "Incorrect number of comments returned");
        $this->assertEquals($commentTexts[0], $comments[0]['comment'], "Comment text mismatch");
    }

    // Enhanced TimeFormatter Tests
    public function testTimeFormatterWithVariousTimeframes() {
        // Test minutes ago
        $timestamp = time() - 300; // 5 minutes ago
        $this->assertEquals('5 minutes ago', TimeFormatter::formatTimestamp($timestamp));

        // Test hours ago
        $timestamp = time() - 7200; // 2 hours ago
        $this->assertEquals('2 hours ago', TimeFormatter::formatTimestamp($timestamp));

        // Test days ago
        $timestamp = time() - 172800; // 2 days ago
        $this->assertEquals('2 days ago', TimeFormatter::formatTimestamp($timestamp));

        // Test date format for old dates
        $oldDate = strtotime('2022-01-01');
        $this->assertEquals('Jan 01, 2022', TimeFormatter::formatTimestamp($oldDate));
    }

    // Helper methods
    private function createTestUser(): int {
        $user = new User(self::$pdo);
        $username = 'testuser_' . uniqid();
        $user->registerUser($username, $username . '@example.com', 'password123');
        return (int)self::$pdo->lastInsertId();
    }

    private function createTestTopic(int $userId): int {
        $topic = new Topic(self::$pdo);
        $topic->createTopic($userId, 'Test Topic ' . uniqid(), 'Test Description');
        return (int)self::$pdo->lastInsertId();
    }
}
