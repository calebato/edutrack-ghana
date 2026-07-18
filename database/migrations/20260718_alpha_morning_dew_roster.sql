-- Authorized Alpha Morning Dew Montessori roster update (13 supplied names).
START TRANSACTION;

UPDATE schools SET name='Alpha Morning Dew Montessori', region='Greater Accra', district='Ablekuma' WHERE id=1;
UPDATE students SET school_id=1 WHERE school_id<>1;
UPDATE teachers SET school_id=1 WHERE school_id<>1;
UPDATE topics SET school_id=1 WHERE school_id IS NOT NULL AND school_id<>1;
UPDATE teachers SET subject='General' WHERE email='duriel@edutrack.com';
UPDATE teachers SET subject='Social Studies' WHERE email='teacher1@edutrack.test';

-- Repurpose the evaluation-only account while retaining its activity history.
UPDATE students SET full_name='Michael Owusu',email='michael.owusu@alphamorningdew.edu.gh',student_id='AMD-S-008',class_level='JHS1',gender='Male',school_id=1,is_active=1,must_change_password=1 WHERE id=8;

INSERT INTO students (school_id,full_name,email,password_hash,student_id,class_level,gender,learning_style,difficulty_level,total_points,current_streak,longest_streak,last_activity_date,is_active,must_change_password)
SELECT 1,'Abigail Mensah','abigail.mensah@alphamorningdew.edu.gh','$2y$10$HzyGUvalh.jCn45ow30cdenEFyMjhgsyqEglUKfnidYKV6unJ4cIW','AMD-S-009','JHS1','Female','visual','intermediate',620,4,7,CURDATE(),1,1 WHERE NOT EXISTS(SELECT 1 FROM students WHERE student_id='AMD-S-009');
INSERT INTO students (school_id,full_name,email,password_hash,student_id,class_level,gender,learning_style,difficulty_level,total_points,current_streak,longest_streak,last_activity_date,is_active,must_change_password)
SELECT 1,'Daniel Ofori','daniel.ofori@alphamorningdew.edu.gh','$2y$10$HzyGUvalh.jCn45ow30cdenEFyMjhgsyqEglUKfnidYKV6unJ4cIW','AMD-S-010','JHS1','Male','auditory','beginner',280,1,3,CURDATE(),1,1 WHERE NOT EXISTS(SELECT 1 FROM students WHERE student_id='AMD-S-010');
INSERT INTO students (school_id,full_name,email,password_hash,student_id,class_level,gender,learning_style,difficulty_level,total_points,current_streak,longest_streak,last_activity_date,is_active,must_change_password)
SELECT 1,'Priscilla Asante','priscilla.asante@alphamorningdew.edu.gh','$2y$10$HzyGUvalh.jCn45ow30cdenEFyMjhgsyqEglUKfnidYKV6unJ4cIW','AMD-S-011','JHS1','Female','reading','intermediate',710,5,8,CURDATE(),1,1 WHERE NOT EXISTS(SELECT 1 FROM students WHERE student_id='AMD-S-011');
INSERT INTO students (school_id,full_name,email,password_hash,student_id,class_level,gender,learning_style,difficulty_level,total_points,current_streak,longest_streak,last_activity_date,is_active,must_change_password)
SELECT 1,'Emmanuel Boateng','emmanuel.boateng@alphamorningdew.edu.gh','$2y$10$HzyGUvalh.jCn45ow30cdenEFyMjhgsyqEglUKfnidYKV6unJ4cIW','AMD-S-012','JHS1','Male','kinesthetic','beginner',195,0,2,CURDATE(),1,1 WHERE NOT EXISTS(SELECT 1 FROM students WHERE student_id='AMD-S-012');
INSERT INTO students (school_id,full_name,email,password_hash,student_id,class_level,gender,learning_style,difficulty_level,total_points,current_streak,longest_streak,last_activity_date,is_active,must_change_password)
SELECT 1,'Grace Ampofo','grace.ampofo@alphamorningdew.edu.gh','$2y$10$HzyGUvalh.jCn45ow30cdenEFyMjhgsyqEglUKfnidYKV6unJ4cIW','AMD-S-013','JHS2','Female','visual','advanced',880,6,11,CURDATE(),1,1 WHERE NOT EXISTS(SELECT 1 FROM students WHERE student_id='AMD-S-013');
INSERT INTO students (school_id,full_name,email,password_hash,student_id,class_level,gender,learning_style,difficulty_level,total_points,current_streak,longest_streak,last_activity_date,is_active,must_change_password)
SELECT 1,'Samuel Opoku','samuel.opoku@alphamorningdew.edu.gh','$2y$10$HzyGUvalh.jCn45ow30cdenEFyMjhgsyqEglUKfnidYKV6unJ4cIW','AMD-S-014','JHS2','Male','auditory','intermediate',540,3,6,CURDATE(),1,1 WHERE NOT EXISTS(SELECT 1 FROM students WHERE student_id='AMD-S-014');
INSERT INTO students (school_id,full_name,email,password_hash,student_id,class_level,gender,learning_style,difficulty_level,total_points,current_streak,longest_streak,last_activity_date,is_active,must_change_password)
SELECT 1,'Deborah Antwi','deborah.antwi@alphamorningdew.edu.gh','$2y$10$HzyGUvalh.jCn45ow30cdenEFyMjhgsyqEglUKfnidYKV6unJ4cIW','AMD-S-015','JHS2','Female','reading','beginner',225,1,2,CURDATE(),1,1 WHERE NOT EXISTS(SELECT 1 FROM students WHERE student_id='AMD-S-015');
INSERT INTO students (school_id,full_name,email,password_hash,student_id,class_level,gender,learning_style,difficulty_level,total_points,current_streak,longest_streak,last_activity_date,is_active,must_change_password)
SELECT 1,'Isaac Agyeman','isaac.agyeman@alphamorningdew.edu.gh','$2y$10$HzyGUvalh.jCn45ow30cdenEFyMjhgsyqEglUKfnidYKV6unJ4cIW','AMD-S-016','JHS2','Male','kinesthetic','advanced',960,7,13,CURDATE(),1,1 WHERE NOT EXISTS(SELECT 1 FROM students WHERE student_id='AMD-S-016');
INSERT INTO students (school_id,full_name,email,password_hash,student_id,class_level,gender,learning_style,difficulty_level,total_points,current_streak,longest_streak,last_activity_date,is_active,must_change_password)
SELECT 1,'Belinda Addo','belinda.addo@alphamorningdew.edu.gh','$2y$10$HzyGUvalh.jCn45ow30cdenEFyMjhgsyqEglUKfnidYKV6unJ4cIW','AMD-S-017','JHS3','Female','visual','intermediate',665,4,9,CURDATE(),1,1 WHERE NOT EXISTS(SELECT 1 FROM students WHERE student_id='AMD-S-017');
INSERT INTO students (school_id,full_name,email,password_hash,student_id,class_level,gender,learning_style,difficulty_level,total_points,current_streak,longest_streak,last_activity_date,is_active,must_change_password)
SELECT 1,'Joseph Amoah','joseph.amoah@alphamorningdew.edu.gh','$2y$10$HzyGUvalh.jCn45ow30cdenEFyMjhgsyqEglUKfnidYKV6unJ4cIW','AMD-S-018','JHS3','Male','auditory','beginner',310,2,4,CURDATE(),1,1 WHERE NOT EXISTS(SELECT 1 FROM students WHERE student_id='AMD-S-018');
INSERT INTO students (school_id,full_name,email,password_hash,student_id,class_level,gender,learning_style,difficulty_level,total_points,current_streak,longest_streak,last_activity_date,is_active,must_change_password)
SELECT 1,'Anita Nyarko','anita.nyarko@alphamorningdew.edu.gh','$2y$10$HzyGUvalh.jCn45ow30cdenEFyMjhgsyqEglUKfnidYKV6unJ4cIW','AMD-S-019','JHS3','Female','reading','advanced',1030,8,14,CURDATE(),1,1 WHERE NOT EXISTS(SELECT 1 FROM students WHERE student_id='AMD-S-019');
INSERT INTO students (school_id,full_name,email,password_hash,student_id,class_level,gender,learning_style,difficulty_level,total_points,current_streak,longest_streak,last_activity_date,is_active,must_change_password)
SELECT 1,'Kelvin Frimpong','kelvin.frimpong@alphamorningdew.edu.gh','$2y$10$HzyGUvalh.jCn45ow30cdenEFyMjhgsyqEglUKfnidYKV6unJ4cIW','AMD-S-020','JHS3','Male','kinesthetic','intermediate',590,3,7,CURDATE(),1,1 WHERE NOT EXISTS(SELECT 1 FROM students WHERE student_id='AMD-S-020');

-- Completed topic records for every learner.
INSERT INTO topic_progress(student_id,topic_id,status,time_spent_minutes,completion_percent,started_at,completed_at)
SELECT s.id,1,'completed',25+MOD(s.id,25),100,DATE_SUB(NOW(),INTERVAL 12 DAY),DATE_SUB(NOW(),INTERVAL 10 DAY)
FROM students s WHERE s.school_id=1 AND NOT EXISTS(SELECT 1 FROM topic_progress p WHERE p.student_id=s.id AND p.topic_id=1);

-- Varied results deliberately include weak/at-risk learners (scores below 50).
INSERT INTO quiz_attempts(student_id,quiz_id,score,total_questions,correct_answers,time_taken_seconds,passed,attempt_number,answers_json,question_ids_json,started_at,completed_at)
SELECT s.id,1,
CASE WHEN MOD(s.id,7)=0 THEN 20 WHEN MOD(s.id,5)=0 THEN 40 ELSE 60+MOD(s.id,4)*10 END,
5,CASE WHEN MOD(s.id,7)=0 THEN 1 WHEN MOD(s.id,5)=0 THEN 2 ELSE 3+MOD(s.id,3) END,
240+MOD(s.id,180),CASE WHEN MOD(s.id,7)=0 OR MOD(s.id,5)=0 THEN 0 ELSE 1 END,1,
'{"1":"C","2":"C","3":"B","4":"C","5":"B"}','[1,2,3,4,5]',DATE_SUB(NOW(),INTERVAL 7 DAY),DATE_SUB(NOW(),INTERVAL 7 DAY)
FROM students s WHERE s.school_id=1 AND NOT EXISTS(SELECT 1 FROM quiz_attempts a WHERE a.student_id=s.id AND a.quiz_id=1 AND a.completed_at IS NOT NULL);

-- All dependent records now point to the official school.
DELETE FROM schools WHERE id<>1;

COMMIT;
